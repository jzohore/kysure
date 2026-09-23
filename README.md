# KYSURE

SaaS B2B d'automatisation de la conformité réglementaire (KYC, LCB-FT, AMF/ACPR) pour les CGP et courtiers.

Symfony 8 · PHP 8.4 · FrankenPHP · PostgreSQL · Messenger — architecture hexagonale (DDD, event-driven).

## Démarrer

```bash
docker compose build --pull --no-cache
docker compose up --wait
```

Puis ouvrir `https://localhost` (certificat TLS auto-généré). `docker compose down --remove-orphans` pour arrêter.

## Commandes utiles

```bash
composer format   # rector + php-cs-fixer
composer check    # rector, cs, lint, phpstan, deptrac, audit
docker compose exec php vendor/bin/phpunit
```

## Documentation

- Conventions, architecture et rituel de commit : [CLAUDE.md](CLAUDE.md)
- Documentation par fonctionnalité : [docs/features](docs/features/README.md)
