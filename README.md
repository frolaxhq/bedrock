<div align="center">
    <h1>Bedrock</h1>
</div>

<p align="center">
    <a href="https://packagist.org/packages/frolaxhq/bedrock"><img src="https://img.shields.io/packagist/v/frolaxhq/bedrock.svg?style=flat-square" alt="Packagist"></a>
    <a href="https://packagist.org/packages/frolaxhq/bedrock"><img src="https://img.shields.io/packagist/php-v/frolaxhq/bedrock.svg?style=flat-square" alt="PHP from Packagist"></a>
    <a href="https://packagist.org/packages/frolaxhq/bedrock"><img src="https://badge.laravel.cloud/badge/frolaxhq/bedrock?style=flat" alt="Laravel versions"></a>
    <a href="https://github.com/frolaxhq/bedrock/actions"><img alt="GitHub Workflow Status (main)" src="https://img.shields.io/github/actions/workflow/status/frolaxhq/bedrock/tests.yml?branch=main&label=Tests&style=flat-square"></a>
    <a href="https://packagist.org/packages/frolaxhq/bedrock"><img src="https://img.shields.io/packagist/dt/frolaxhq/bedrock.svg?style=flat-square" alt="Total Downloads"></a>
</p>

This is a frolax package

## Installation

You can install the package via Composer:

```bash
composer require frolaxhq/bedrock
```

You may publish all of the package's resources at once:

```bash
php artisan vendor:publish --tag="bedrock"
```

Or, you may publish each resource individually:

### Publishing the Configuration File

```bash
php artisan vendor:publish --tag="bedrock-config"
```

### Publishing and Running the Migrations

```bash
php artisan vendor:publish --tag="bedrock-migrations"
php artisan migrate
```

### Publishing the Views

```bash
php artisan vendor:publish --tag="bedrock-views"
```

### Publishing the Translations

```bash
php artisan vendor:publish --tag="bedrock-lang"
```

### Publishing the Public Assets

```bash
php artisan vendor:publish --tag="bedrock-assets"
```

## Usage

<!-- Add a basic usage example here. -->

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Thank you for considering contributing to Bedrock! Please review our [contributing guide](.github/CONTRIBUTING.md) to get started.

## Security Vulnerabilities

Please review [our security policy](.github/SECURITY.md) on how to report security vulnerabilities.

## Credits

- [Bishwajit Adhikary](https://github.com/frolaxhq)
- [All Contributors](../../contributors)

## License

Bedrock is open-sourced software licensed under the [MIT license](LICENSE.md).
