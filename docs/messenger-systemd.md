# Worker Messenger supervisé par systemd

L’unité fournie lance un seul consommateur du transport Doctrine `async`. Elle le redémarre après un arrêt, un dépassement mémoire, une limite d’une heure ou une erreur fatale. Le consommateur est volontairement renouvelé toutes les heures pour prendre en compte les déploiements et limiter les effets d’un processus PHP long.

Le fichier `deploy/systemd/zunaflux-messenger.service` cible l’hôte actuel : utilisateur `zunaflux`, projet `/home/zunaflux/zunaflux` et PHP Remi 8.5 `/opt/remi/php85/root/usr/bin/php`. Vérifier ces trois valeurs avant installation.

## Installation

```bash
sudo install -o root -g root -m 0644 deploy/systemd/zunaflux-messenger.service /etc/systemd/system/zunaflux-messenger.service
sudo systemctl daemon-reload
sudo systemctl enable --now zunaflux-messenger.service
sudo systemctl status zunaflux-messenger.service --no-pager
```

Le statut attendu est `active (running)`. L’application doit alors afficher le worker actif au plus tard 30 secondes après son démarrage.

## Journaux et diagnostic

```bash
sudo journalctl -u zunaflux-messenger.service -f
sudo systemctl status zunaflux-messenger.service --no-pager
```

## Déploiement applicatif

Après le déploiement du code et avant de réchauffer le cache, arrêter le worker. Après le réchauffement, le redémarrer :

```bash
sudo systemctl stop zunaflux-messenger.service
php85 bin/console cache:clear --env=prod
php85 bin/console cache:warmup --env=prod
sudo systemctl start zunaflux-messenger.service
sudo systemctl status zunaflux-messenger.service --no-pager
```

`systemctl reload zunaflux-messenger.service` demande un arrêt propre au worker ; systemd en démarre alors un nouveau automatiquement.
