<?php

namespace Packeton\Controller;

use Doctrine\Persistence\ManagerRegistry;
use Packeton\Attribute\Vars;
use Packeton\Composer\JsonResponse;
use Packeton\Entity\Package;
use Packeton\Entity\Version;
use Packeton\Model\PackageManager;
use Packeton\Service\DistManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class ProviderController extends AbstractController
{
    use ControllerTrait;

    public function __construct(
        private readonly PackageManager $packageManager,
        private readonly ManagerRegistry $registry,
    ){}

    /**
     * @Route("/packages.json", name="root_packages", defaults={"_format" = "json"}, methods={"GET"})
     */
    public function packagesAction()
    {
        $rootPackages = $this->packageManager->getRootPackagesJson($this->getUser());

        return new JsonResponse($rootPackages);
    }

    /**
     * @Route(
     *     "/p/providers${hash}.json",
     *     requirements={"hash"="[a-f0-9]+"},
     *     name="root_providers", defaults={"_format" = "json"},
     *     methods={"GET"}
     * )
     *
     * @param string $hash
     * @return Response
     */
    public function providersAction($hash)
    {
        $providers = $this->packageManager->getProvidersJson($this->getUser(), $hash);
        if (!$providers) {
            return $this->createNotFound();
        }

        return new JsonResponse($providers);
    }

    /**
     * Copy from Packagist. Can be used for https://workers.cloudflare.com sync mirrors.
     * Used two unix format: Packagist and RFC-3399
     *
     * @Route("/metadata/changes.json", name="metadata_changes", methods={"GET"})
     */
    public function metadataChangesAction(Request $request)
    {
        $now = time() * 10000;
        $since = $request->query->getInt('since');
        // Added unix
        if ($since > 1585061224 && $since < 15850612240000) {
            $since *= 10000;
        }

        $oldestSyncPoint = $now - 30 * 86400 * 10000;
        if (!$since || $since < $now - 15850612240000) {
            return new JsonResponse(['error' => 'Invalid or missing "since" query parameter, make sure you store the timestamp at the initial point you started mirroring, then send that to begin receiving changes, e.g. '.$this->generateUrl('metadata_changes', ['since' => $now], UrlGeneratorInterface::ABSOLUTE_URL).' for example.', 'timestamp' => $now], 400);
        }
        if ($since < $oldestSyncPoint) {
            return new JsonResponse(['actions' => [['type' => 'resync', 'time' => floor($now / 10000), 'package' => '*']], 'timestamp' => $now]);
        }

        // Only update action support.
        $updatesDev = $this->registry->getRepository(Package::class)
            ->getMetadataChanges(floor($since/10000), floor($now/10000), false);
        $updatesStab = $this->registry->getRepository(Package::class)
            ->getMetadataChanges(floor($since/10000), floor($now/10000), true);

        return new JsonResponse(['actions' => array_merge($updatesDev, $updatesStab), 'timestamp' => $now]);
    }

    /**
     * @Route(
     *     "/p/{package}.json",
     *     requirements={"package"="[\w+\/\-\$]+"},
     *     name="root_package", defaults={"_format" = "json"},
     *     methods={"GET"}
     * )
     *
     * @param string $package
     * @return Response
     */
    public function packageAction(string $package)
    {
        $package = \explode('$', $package);
        if (\count($package) !== 2) {
            $package = $this->packageManager->getPackageJson($this->getUser(), $package[0]);
            if ($package) {
                return new JsonResponse($package);
            }
            return $this->createNotFound();
        }

        $package = $this->packageManager->getCachedPackageJson($this->getUser(), $package[0], $package[1]);
        if (!$package) {
            return $this->createNotFound();
        }

        return new JsonResponse($package);
    }

    /**
     * @Route(
     *     "/p2/{package}.json",
     *     requirements={"package"="[\w+\/\-\~]+"},
     *     name="root_package_v2", defaults={"_format" = "json"},
     *     methods={"GET"}
     * )
     */
    public function packageV2Action(Request $request, string $package)
    {
        $isDev = str_ends_with($package, '~dev');
        $package = preg_replace('/~dev$/', '', $package);

        $package = $this->packageManager->getPackageV2Json($this->getUser(), $package, $isDev, $lastModified);
        if (!$package) {
            return $this->createNotFound();
        }

        $response = new JsonResponse($package);
        $response->setLastModified(new \DateTime($lastModified));
        $response->isNotModified($request);

        return $response;
    }

    /**
     * @Route(
     *     "/zipball/{package}/{hash}",
     *     name="download_dist_package",
     *     requirements={"package"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?", "hash"="[a-f0-9]{40}\.[a-z]+?"},
     *     methods={"GET"}
     * )
     */
    public function zipballAction(#[Vars('name')] Package $package, $hash)
    {
        $distManager = $this->container->get(DistManager::class);
        if (false === \preg_match('{[a-f0-9]{40}}i', $hash, $match) or !($reference = $match[0])) {
            return new JsonResponse(['status' => 'error', 'message' => 'Not Found'], 404);
        }

        $version = $package->getVersions()->findFirst(fn($k, $v) => $v->getReference() === $reference);

        if ($version instanceof Version) {
            if ($this->isGranted('ROLE_FULL_CUSTOMER', $version) and $path = $distManager->getDistPath($version)) {
                return new BinaryFileResponse($path);
            }

            return $this->createNotFound();
        }

        try {
            $path = $distManager->getDistByOrphanedRef($reference, $package, $version);
            $version = $package->getVersions()->findFirst(fn($k, $v) => $v->getVersion() === $version);

            if ($this->isGranted('ROLE_FULL_CUSTOMER', $version) || $this->isGranted('VIEW_ALL_VERSION', $package)) {
                return new BinaryFileResponse($path);
            }
        } catch (\Exception $e) {
            $msg = $this->isGranted('ROLE_MAINTAINER') ? $e->getMessage() : null;
            return $this->createNotFound($msg);
        }

        return $this->createNotFound();
    }

    protected function createNotFound(?string $msg = null)
    {
        return new JsonResponse(['status' => 'error', 'message' => $msg ?: 'Not Found'], 404);
    }

    /**
     * {@inheritDoc}
     */
    public static function getSubscribedServices(): array
    {
        return array_merge(
            parent::getSubscribedServices(),
            [
                DistManager::class
            ]
        );
    }
}