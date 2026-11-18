# Site VIP Lift – page d'accueil, simulateur et articles

- `nouveau/` : version de test (invisible pour Google), envoyée automatiquement dans `viplift/nouveau/` (adresse : vip-lift.com/nouveau/) à chaque modification.
- `mise-en-ligne/` : version définitive, envoyée dans `viplift/` (dossier racine du site) uniquement quand la branche `production` est mise à jour, ou par l'action « 2 - Mise en ligne » (taper OUI).
- « 3 - Retour en arrière » (branche `retour-arriere` ou action manuelle, taper OUI) : rétablit l'ancienne page d'accueil WordPress en 1 minute.

Secrets à renseigner (Settings › Secrets and variables › Actions) :
- `FTP_SERVER`, `FTP_USER`, `FTP_PASSWORD` : espace client OVH › Hébergements › vip-lift.com › FTP-SSH ;
- `SMTP_PASSWORD` : mot de passe de la boîte hello@vip-lift.com (formulaire de contact). Il est écrit à chaque envoi dans `vip-lift-config.php`, à la racine de l'hébergement, hors du dossier www.
