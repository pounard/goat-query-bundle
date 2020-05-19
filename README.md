# Goat Query Symfony bundle

This packages provides autoconfiguration and integration of
[makinacorpus/goat-query](https://github.com/pounard/goat-query)
for the Symfony framework.

# Installation

```sh
composer req makinacorpus/goat-query-bundle
```

Then add the following bundles into your Symfony `config/bundles.php` file:

```php
return [
    // Your bundles...
    Goat\Query\Symfony\GoatQueryBundle::class => ['all' => true],
];
```

# Usage

If everything was successfuly configured, you may use one of the following
classes in dependency injection context (ie. services constructor arguments
or controllers action methods parameters):

 - `Goat\Runner\Runner` gives you a runner instance plugged onto the default
   Doctrine DBAL connection,

 - `Goat\Query\QueryBuilder` gives you a query factory instance.

# Advanced configuration

## Runners

A runner is a connection, you may have one or more. Per default, you should
always configure the `default` connection.

### Re-using a Doctrine connection

```yaml
goat_query:
    runner:
        default:
              doctrine_connection: default
              driver: doctrine
              metadata_cache: apcu
              metadata_cache_prefix: "%goat.runner.metadata_cache_prefix%"
    query:
        enabled: true
```

### Using the ext-pgsql driver

```yaml
goat_query:
    runner:
        default:
              driver: ext-pgsql
              url: '%env(resolve:DATABASE_URL)%'
        logging:
              driver: ext-pgsql
              url: '%env(resolve:DATABASE_URL)%'
```

You will notice that for `ext-pgsl` we do not configure a metadata cache,
because `ext-pgsql` is very fast and using APCu to store metadata doesn't
bring any performance boost (it would slower the runner actually).

### Using &lt;any&gt; driver

The previous section works for any driver, just replace all `ext-pgsql` section
by any of:

 - `ext-pgsql` : for PostgreSQL via PHP `ext-pgsql`,
 - `pdo-pgsql` : for PostgreSQL via `PDO`,
 - `pgsql` : for PostgreSQL via driver autoselection (`ext-pgsql` is prefered),
 - `pdo-mysql` : for MySQL via `PDO`,
 - `mysql` : for MySQL via driver autoselection (only `PDO` is supported right now).

### More than one database connexion

```yaml
goat_query:
    runner:
        default:
              doctrine_connection: default
              driver: doctrine
              metadata_cache: apcu
              metadata_cache_prefix: "%goat.runner.metadata_cache_prefix%"
        some_business_connection:
              driver: ext-pgsql
              url: '%env(resolve:ANOTHER_DATABASE_URL)%'
        logging:
              autocommit: true
              doctrine_connection: another_connnection
              driver: doctrine
              metadata_cache: apcu
              metadata_cache_prefix: "%goat.runner.metadata_cache_prefix%"
    query:
        enabled: true
```

The `default` runner is the one that per default will be injected into services
using the `\Goat\Runner\Runner` interface as type-hint when using autowiring.

In order to inject a specific runner, you may use the `goat.runner.NAME` service
identifier, in the above example, you would have the following two services
available:

 - `goat.runner.default`, the default one,
 - `goat.runner.logging`, the other one.

## Driver configuration

Read the `` file in this package for more information.

@todo

