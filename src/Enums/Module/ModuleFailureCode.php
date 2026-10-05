<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Enums\Module;

enum ModuleFailureCode: string
{
    case DirectoryMissing = 'path.missing';
    case ManifestInvalid = 'manifest.invalid';
    case VersionDrift = 'version.drift';
    case AutoloadInvalid = 'autoload.invalid';
    case ProviderMissing = 'provider.missing';
    case CoreIncompatible = 'core.incompatible';
    case PackageMissing = 'package.missing';
    case PackageIncompatible = 'package.incompatible';
    case DependencyMissing = 'dependency.missing';
    case DependencyDisabled = 'dependency.disabled';
    case DependencyIncompatible = 'dependency.incompatible';
    case DependencyCycle = 'dependency.cycle';
    case DatabaseFailed = 'database.failed';
    case LockUnavailable = 'lock.unavailable';
    case BootstrapFailed = 'bootstrap.failed';
    case FrontendArtifactMissing = 'frontend.artifact_missing';
    case FrontendArtifactInvalid = 'frontend.artifact_invalid';
    case FrontendIncompatible = 'frontend.incompatible';
    case FrontendPublishFailed = 'frontend.publish_failed';
    case FrontendClientFailed = 'frontend.client_failed';
    case FrontendSsrFailed = 'frontend.ssr_failed';
}
