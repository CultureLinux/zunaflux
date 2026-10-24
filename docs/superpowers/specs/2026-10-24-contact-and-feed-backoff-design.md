# Contact et temporisation de collecte — conception

## Intention

Permettre aux personnes utilisant Zunaflux de joindre l'équipe à
`products@clinux.fr`, tout en rendant la collecte plus respectueuse des flux
temporairement indisponibles.

## Décisions

- La page publique `/contact` comporte les champs nom, e-mail, sujet et
  message ; les messages sont envoyés par le Mailer Symfony déjà configuré.
- Le formulaire est protégé par CSRF, valide les longueurs et l'adresse de
  réponse, et limite les envois par adresse IP.
- Le sujet et le corps sont traités comme du texte brut ; aucune donnée de
  formulaire ne sera journalisée.
- Le collecteur envoie le User-Agent
  `Zunaflux/1.0 (+https://zunaflux.clinux.fr/contact)`.
- Après un échec, un flux est temporairement exclu de l'enqueue global. Le
  délai double à chaque échec consécutif, de 1 h à 24 h maximum. Une collecte
  réussie remet le compteur à zéro et autorise immédiatement l'enqueue futur.
- La date de la prochaine tentative est persistée dans MariaDB, afin de
  survivre aux redémarrages des workers.

## Hors périmètre

- Aucun contournement de protection anti-bot ou de limitation imposée par les
  éditeurs de flux.
- Aucun stockage durable des messages de contact.
- Aucun changement de cadence du planificateur : il continue à déclencher la
  commande de collecte existante.

## Compatibilité

Une migration MariaDB 10.11 ajoute une colonne nullable de temporisation à
`feed`. Les flux existants restent collectables immédiatement.
