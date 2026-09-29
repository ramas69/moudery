# Caisses Moudery — Cahier des charges & PRD

Sep 26, 2026 · @Rama

## 1. Contexte, objectifs et périmètre

L'objectif est de livrer en 8 semaines un SaaS Symfony / MySQL qui permet à l'association de la diaspora du village de Moudery de gérer les caisses de ses villes, d'encaisser en ligne et de rendre des comptes transparents à ses membres.

### Contexte

- L'association est organisée par villes, toutes situées en France. Chaque ville a son propre compte bancaire.
- Chaque ville collecte des cotisations fixes et des contributions ponctuelles (décès, projets, fêtes).
- Une partie des sommes reste à la ville, l'autre est reversée au bureau central. Le taux dépend du type de contribution.
- Aujourd'hui, les données existent dans des fichiers (Excel / Google Sheets) qu'il faudra pouvoir importer.

### Objectifs

1. Centraliser la gestion des membres, cotisations, contributions et dépenses de toutes les villes.
2. Permettre aux membres de payer en ligne par carte, et automatiser la répartition ville / central.
3. Réduire les impayés grâce aux relances automatiques par email et à un suivi pour les trésoriers.
4. Rendre les comptes transparents : chaque membre voit les entrées et dépenses de sa ville, sans les noms.
5. Produire les documents de l'assemblée générale (tableaux de bord, exports PDF et Excel).

### Périmètre

- **Premier client :** l'association de Moudery, gratuitement. Le modèle économique pour les autres associations sera défini plus tard.
- **Architecture multi-tenant dès la V1**, pour accueillir ensuite d'autres villages et associations sans refonte.
- **Support :** site web responsive uniquement (pas d'app mobile).
- **Monnaie :** euro uniquement.
- **Hors V1 :** WhatsApp et SMS, application mobile, multi-devises, facturation du SaaS aux associations.
- **Équipe :** une développeuse (Rama), seule sur le projet.

## 2. Acteurs, rôles et périmètres d'accès

Chaque rôle est attribué **sur un périmètre** : une ville précise, ou toute l'association. Le trésorier de Lyon n'a aucun accès aux données de Marseille.

### Principe du modèle de droits

- **Permissions fines**, par exemple `depense.creer`, `depense.valider`, `membre.inviter`, `rapport.exporter`.
- **Rôles** = regroupements de permissions.
- **Affectation** = un utilisateur, un rôle, un périmètre (ville ou association).
- **V1 :** les rôles sont prédéfinis, mais le code repose déjà sur ce socle de permissions.
- **V2 :** chaque association peut créer et modifier ses propres rôles.

### Rôles prédéfinis en V1

| Rôle | Périmètre | Droits principaux |
| --- | --- | --- |
| Super-admin plateforme | Toute la plateforme | Créer une association (tenant), support technique. Aucun accès aux données financières par défaut. |
| Bureau central | Association | Tout voir en consolidé, gérer les villes, les types de contribution et les taux de reversement, lancer des appels à l'échelle de l'association, valider les dépenses du central. |
| Trésorier de ville | Ville | Gérer les membres et les foyers, saisir les paiements manuels (espèces, virement), saisir les dépenses, suivre les impayés, relancer, exporter. |
| Président de ville | Ville | Valider ou refuser les dépenses de la ville, lancer des appels de ville, consulter les rapports. |
| Secrétaire de ville | Ville | Gérer les membres (invitations, validation des inscriptions), consulter. |
| Membre | Son compte + sa ville | Payer en ligne, voir son historique et ses reçus, voir les entrées (anonymisées) et les dépenses de sa ville. |

### Règles importantes

- Une même personne peut cumuler plusieurs rôles, par exemple membre et trésorier.
- Une dépense ne peut pas être validée par la personne qui l'a saisie (séparation des tâches).
- Toutes les actions sensibles sont tracées : validation, modification de montant, changement de rôle.
- Décidé le 29 septembre 2026 : seul le président de la ville valide les dépenses de sa ville ; le bureau central ne les valide pas, même en l'absence du président.

## 3. Règles de gestion

Toute somme encaissée est rattachée à un type de contribution. C'est ce type qui fixe qui paie (personne ou foyer) et quelle part revient au central.

### Types de contribution

Chaque association configure ses types. Exemples :

| Type | Unité de paiement | Montant | Reversement au central |
| --- | --- | --- | --- |
| Cotisation annuelle | Personne | Fixe, défini par l'association | Taux défini par le type, par ex. 30 % |
| Contribution décès | Foyer | Fixe ou libre selon l'appel | Taux défini par le type |
| Projet village | Personne ou foyer | Libre, avec montant suggéré | Souvent 100 % au central |
| Fête de ville | Personne | Fixe | 0 %, reste à la ville |

Les montants et taux ci-dessus sont des exemples, à confirmer avec l'association.

### Cotisations fixes

- Une cotisation a une période (année ou mois) et une date d'échéance.
- Un membre est « à jour » quand toutes ses cotisations échues sont payées.
- Un membre ne paie jamais deux fois la même échéance, que ce soit en son nom ou via son foyer.

### Contributions ponctuelles (appels)

- Un appel est lancé soit par le bureau central (périmètre : toute l'association), soit par une ville (périmètre : ses membres).
- Un appel a un type, un titre, une description, un montant fixe ou libre, une date limite et un statut (brouillon, ouvert, clôturé).
- Un appel de ville peut surcharger le taux de reversement de son type, par exemple 0 % pour un projet purement local.

### Foyers

- Un foyer regroupe plusieurs membres d'une même ville, avec un membre payeur désigné.
- Pour une contribution « par foyer », seul le payeur reçoit la demande. Le paiement vaut pour tout le foyer.

### Reversements au central

- **Paiement en ligne :** la répartition est automatique au moment du paiement, via Stripe Connect. La part de la ville va sur son compte, la part du central sur le compte du central.
- **Paiement manuel** (espèces, virement direct) : le trésorier l'enregistre et l'app calcule la dette de la ville envers le central. La ville fait ensuite le virement et le trésorier le déclare comme « reversement effectué ».
- L'app affiche en permanence, par ville, le montant restant à reverser.
- Arrondi : la part du central est arrondie au centime inférieur, le reste va à la ville.
- Les frais Stripe sont supportés par la ville, le central reçoit sa part nette de frais. **Point à valider avec l'association.**

### Dépenses

- Cycle de vie : brouillon → soumise → validée ou refusée → payée.
- Un justificatif (PDF ou photo) est obligatoire pour soumettre.
- Seule une dépense validée peut être marquée payée. Le solde de la caisse n'est impacté qu'au statut « payée ».
- Refus : le motif est obligatoire.

### Remboursements

- Un paiement en ligne peut être remboursé par le trésorier, avec un motif. La répartition ville / central est annulée en proportion.

## 4. Exigences fonctionnelles V1

La V1 contient dix modules. Chaque exigence porte un identifiant (F-xx) repris dans le PRD.

### M1 — Authentification et comptes

- F-01 : inscription libre d'un membre (nom, prénom, email, téléphone, ville), avec le statut « en attente » jusqu'à validation par un responsable de ville.
- F-02 : invitation par email par un responsable, avec un lien d'activation valable 7 jours.
- F-03 : connexion par email et mot de passe, et réinitialisation du mot de passe.
- F-04 : profil modifiable par le membre (coordonnées, consentements).

### M2 — Organisation (association, villes)

- F-05 : le bureau central crée, modifie et archive les villes, via l'assistant de création (F-40 à F-46).
- F-06 : chaque ville connecte son compte Stripe via l'onboarding Stripe Connect.
- F-07 : attribution des rôles aux utilisateurs, avec leur périmètre.

### M2 bis — Assistant de création d'une ville

Créer une ville revient à ouvrir sa caisse puis à construire ses données, étape par étape. Chaque étape est enregistrée : on peut s'arrêter et reprendre plus tard.

1. **Identité (F-40).** Nom de la ville, obligatoire et unique dans l'association. Invitation des responsables par email : trésorier, président, secrétaire.
2. **Compte bancaire (F-41).** Lancer l'onboarding Stripe tout de suite, ou plus tard. En attendant, seuls les paiements manuels sont possibles.
3. **Membres (F-42).** Importer un fichier CSV / XLSX (même outil que F-31), ajouter des membres à la main, ou partager le lien d'inscription propre à la ville. Les foyers peuvent être créés à cette étape.
4. **Cotisations (F-43).** Les cotisations de l'association s'appliquent automatiquement. La ville peut ajouter ses propres cotisations locales.
5. **Projets et appels (F-44).** Créer les projets de la ville (titre, description, objectif en euros, dates) et, si besoin, lancer un premier appel à contribution. Étape facultative.
6. **Historique (F-45).** Importer les paiements des années précédentes, pour que les soldes et les graphiques démarrent avec les bons chiffres. Étape facultative.
7. **Récapitulatif et activation (F-46).** Tant que la ville est en brouillon, elle est invisible des membres et aucun email ne part. L'activation envoie les invitations et génère les premières échéances.

Règles :

- Seul le bureau central crée une ville. Une fois invité, le trésorier de la ville peut terminer les étapes 3 à 6 lui-même.
- Une ville activée peut être archivée, jamais supprimée : son historique comptable est conservé.
- Un **projet** regroupe ses appels à contribution et ses dépenses. Il affiche sa progression vers l'objectif (graphique G-09).

### M3 — Membres et foyers

- F-08 : liste des membres de la ville, avec recherche, filtres (à jour, en retard, en attente) et fiche détaillée.
- F-09 : création des foyers et désignation du payeur.
- F-10 : transfert d'un membre d'une ville à une autre, avec son historique conservé.

### M4 — Cotisations et appels à contribution

- F-11 : configuration des types de contribution (unité, taux de reversement).
- F-12 : création des cotisations périodiques (montant, période, échéance) et génération automatique des échéances pour chaque membre.
- F-13 : création d'un appel ponctuel par le central ou une ville, puis notification par email des membres concernés.
- F-14 : suivi d'un appel : montant collecté, nombre de contributeurs, progression vers l'objectif.

### M5 — Paiements

- F-15 : paiement en ligne par carte via Stripe Checkout, pour une ou plusieurs échéances à la fois.
- F-16 : répartition automatique ville / central au moment du paiement.
- F-17 : saisie d'un paiement manuel (espèces, virement) par le trésorier.
- F-18 : reçu PDF envoyé par email après chaque paiement.
- F-19 : remboursement d'un paiement en ligne par le trésorier.
- F-20 : suivi des reversements dus au central pour les paiements manuels.

### M6 — Dépenses

- F-21 : saisie d'une dépense (montant, catégorie, date, bénéficiaire, justificatif).
- F-22 : workflow de validation (soumise → validée ou refusée → payée), avec notification par email à chaque étape.
- F-23 : historique des validations (qui, quand, motif).

### M7 — Relances

- F-24 : relances automatiques par email, par exemple à J-7, le jour J et à J+15 de l'échéance. Le calendrier est paramétrable par l'association.
- F-25 : tableau des impayés pour le trésorier, avec relance manuelle en un clic (individuelle ou groupée).

### M8 — Espace membre et transparence

- F-26 : tableau de bord membre avec ses échéances à payer, son historique et ses reçus.
- F-27 : vue de la ville : total des entrées par type (anonymisées), liste des dépenses payées avec leur catégorie, solde.

### M9 — Rapports, exports et import

- F-28 : tableau de bord trésorier et central : encaissé, dépensé, solde, taux de membres à jour, reversements, par période et par ville.
- F-29 : export Excel (XLSX) des mouvements, des membres et des impayés.
- F-30 : export PDF du rapport financier annuel pour l'assemblée générale.
- F-31 : import CSV / XLSX des membres et de l'historique des paiements, avec correspondance des colonnes, aperçu des erreurs ligne par ligne et annulation d'un import complet.

### M10 — Graphiques et tableaux de bord

Les tableaux de bord sont un point fort du produit : des graphiques interactifs, filtrables et réutilisés tels quels dans le rapport d'AG.

#### Catalogue des graphiques

| Réf. | Graphique | Type | Vu par | Question à laquelle il répond |
| --- | --- | --- | --- | --- |
| G-01 | Encaissements par mois, empilés par type de contribution | Barres empilées + courbe de cumul | Central, trésorier | Combien entre, et d'où ? |
| G-02 | Flux de l'argent : membres → villes → central et dépenses | Diagramme de Sankey | Central, rapport d'AG | Où va chaque euro collecté ? |
| G-03 | Carte de France des villes, bulle proportionnelle au montant collecté | Carte géographique à bulles | Central | Quelles villes pèsent le plus ? |
| G-04 | Comparatif des villes : encaissé, dépensé, reversé | Barres groupées horizontales | Central | Quelle ville est en avance ou en retard ? |
| G-05 | Taux de membres à jour, par ville | Jauge (ville) ou barres classées (central) | Central, trésorier | Où faut-il relancer ? |
| G-06 | Impayés par ancienneté : 0–30 j, 31–60 j, plus de 60 j | Barres empilées | Trésorier | Quelle est l'urgence des impayés ? |
| G-07 | Évolution du solde de la caisse | Courbe en aire | Trésorier, membre | La caisse monte-t-elle ou baisse-t-elle ? |
| G-08 | Dépenses par catégorie | Anneau (donut) avec total au centre | Trésorier, membre | À quoi sert l'argent ? |
| G-09 | Avancement d'un appel à contribution | Barre de progression + courbe des dons jour par jour | Tous | Où en est la collecte ? |
| G-10 | Calendrier des paiements | Heatmap calendaire (un carré par jour) | Trésorier | À quels moments les membres paient-ils ? |
| G-11 | Reversements dus et reçus par ville | Barres groupées | Central | Qui doit encore de l'argent au central ? |
| G-12 | Indicateurs clés : collecté, dépensé, solde, % à jour, variation vs année précédente | Cartes KPI avec mini-courbe (sparkline) | Tous (selon le périmètre) | L'essentiel en un coup d'œil |

La vue membre ne montre que G-07, G-08, G-09 et G-12, toujours sans noms (règle d'anonymisation).

#### Exigences

- F-32 : filtres communs à toute la page (période, ville, type de contribution), appliqués à tous les graphiques en même temps.
- F-33 : interactivité — info-bulle au survol ou au toucher, légende cliquable pour masquer une série, clic sur une barre pour ouvrir la liste des mouvements correspondants.
- F-34 : comparaison avec la période précédente, activable en un clic (G-01, G-07, G-12).
- F-35 : export de chaque graphique en PNG, et intégration automatique dans le PDF d'AG.
- F-36 : affichage mobile soigné — les graphiques s'empilent, les étiquettes restent lisibles, les barres horizontales remplacent les verticales sur petit écran.
- F-37 : accessibilité — l'information ne repose jamais sur la couleur seule, et chaque graphique a un tableau de données consultable.
- F-38 : animations d'entrée légères et chargement progressif (squelette gris pendant le calcul).
- F-39 : palette et typographie communes à tout le produit, avec un mode sombre.

#### Règles techniques

- Les données sont agrégées côté serveur, par des requêtes SQL `GROUP BY`, et jamais recalculées dans le navigateur à partir des mouvements bruts.
- Chaque graphique a son propre endpoint JSON, contrôlé par les mêmes Voters que le reste de l'app.
- Les agrégats lourds (G-02, G-03, G-10) sont mis en cache et invalidés à chaque nouveau paiement ou dépense.
- Les chiffres des graphiques, des KPI et des exports doivent être strictement identiques : une seule couche de calcul (un service `StatsService`) les alimente tous.

#### Choix de la librairie

| Option | Points forts | Limites |
| --- | --- | --- |
| Apache ECharts (recommandé) | Couvre tout le catalogue nativement : Sankey, carte, heatmap calendaire, jauge. Export PNG intégré, très bon rendu mobile, mode sombre. | Pas de bundle Symfony officiel : intégration via un petit contrôleur Stimulus. |
| Chart.js via Symfony UX Chart.js | Intégration Symfony officielle et très simple, léger. | Pas de Sankey, de carte ni de heatmap sans plugins tiers : G-02, G-03 et G-10 seraient difficiles. |
| Mix des deux | Chart.js pour le simple, ECharts pour le reste. | Deux styles et deux API à maintenir : déconseillé seule sur 8 semaines. |

Recommandation : **ECharts pour tout**, avec un seul contrôleur Stimulus générique (`chart_controller`) qui reçoit l'URL de l'endpoint et le type de graphique. Pour le PDF d'AG, Gotenberg (Chromium) rend la page HTML avec ses graphiques avant l'impression.

## 5. Exigences non fonctionnelles

La priorité absolue est l'isolation des données : une fuite entre deux associations, ou entre deux villes, est le risque le plus grave du projet.

### Multi-tenant

- Une seule base MySQL, avec une colonne `association_id` sur toutes les tables métier.
- Un filtre Doctrine global (SQL Filter) ajoute automatiquement la condition sur l'association courante à chaque requête.
- Tests automatisés dédiés : un utilisateur de l'association A ne doit jamais lire ni modifier une donnée de B.
- Le même principe s'applique aux villes, via les Voters Symfony.

### Sécurité

- HTTPS obligatoire, mots de passe hachés (algorithme par défaut de Symfony, `auto`).
- Protection CSRF sur tous les formulaires, limitation des tentatives de connexion (RateLimiter).
- Aucune donnée de carte bancaire ne transite ni n'est stockée sur nos serveurs : tout passe par Stripe Checkout.
- Signature des webhooks Stripe vérifiée systématiquement.
- Journal d'audit des actions sensibles (validation, remboursement, changement de rôle, import).
- Justificatifs stockés hors du dossier public, servis uniquement après contrôle des droits.
- Double authentification (2FA) : recommandée pour les trésoriers et le bureau central, à placer en V1 si le temps le permet, sinon en V2.

### RGPD

- Données minimales : nom, prénom, email, téléphone, ville, foyer, paiements.
- Consentement explicite aux emails de relance, et lien de désinscription pour les emails non obligatoires.
- Entrées anonymisées dans la vue membre.
- Droit d'accès et de suppression : export des données d'un membre, et anonymisation à la suppression. Les écritures comptables sont conservées de façon anonyme.
- Mentions légales, politique de confidentialité, registre des traitements.
- Hébergement dans l'Union européenne.
- Point à valider : la durée de conservation des données comptables. En France, elle est en général de 10 ans pour les pièces comptables ; à confirmer pour une association.

### Performance et disponibilité

- Pages clés affichées en moins de 2 secondes pour une association de 1 000 membres.
- Envoi des emails et génération des PDF en asynchrone (Symfony Messenger) pour ne pas bloquer l'utilisateur.
- Sauvegarde automatique quotidienne de la base, avec une restauration testée.

### Ergonomie

- Mobile first : la majorité des membres paieront depuis leur téléphone.
- Interface en français. La traduction (i18n) est prévue dans le code mais non traduite en V1.
- Accessibilité : contrastes suffisants, formulaires navigables au clavier.

## 6. Architecture technique

L'app est un monolithe Symfony avec un rendu serveur (Twig + Symfony UX). C'est le choix le plus rapide à livrer seule en 8 semaines : pas d'API séparée ni de front JavaScript à maintenir.

### Stack

| Couche | Choix | Pourquoi |
| --- | --- | --- |
| Framework | Symfony 7.4 LTS, PHP 8.3+ | Version à support long, écosystème complet (sécurité, formulaires, Messenger). |
| Base de données | MySQL 8, Doctrine ORM + migrations | Imposé. Les migrations versionnent le schéma. |
| Front | Twig, Symfony UX Turbo et Live Components, Tailwind via AssetMapper | Interface réactive sans build JavaScript complexe. |
| Graphiques | Apache ECharts + un contrôleur Stimulus générique | Couvre tout le catalogue M10 (Sankey, carte, heatmap, jauge), export PNG et mode sombre intégrés. |
| Paiement | Stripe Checkout + Stripe Connect (comptes Express), SDK `stripe/stripe-php` | Onboarding des villes géré par Stripe, conformité PCI déléguée. |
| Emails | Symfony Mailer + un service type Brevo ou Mailjet | Bonne délivrabilité, offres gratuites suffisantes au départ. |
| Tâches asynchrones | Symfony Messenger (transport Doctrine) + Symfony Scheduler | Relances planifiées, PDF et emails en arrière-plan, sans Redis au départ. |
| PDF | Gotenberg (ou Dompdf) | Reçus et rapports d'AG générés à partir de templates HTML. |
| Import / export Excel | PhpSpreadsheet | Lecture et écriture CSV / XLSX. |
| Tests | PHPUnit + Foundry (fixtures) | Tests d'isolation multi-tenant et des règles de reversement. |
| Hébergement | VPS ou PaaS en Union européenne, Docker | Conformité RGPD, déploiement reproductible. |

### Modèle de données (entités principales)

| Entité | Rôle | Champs clés |
| --- | --- | --- |
| Association | Le tenant | nom, slug, compte Stripe du central, paramètres des relances |
| Ville | Une ville | association, nom, ville, compte Stripe connecté, statut de l'onboarding |
| User | Compte de connexion | email, mot de passe, statut (en attente, actif, désactivé) |
| Membre | Fiche adhérent | user (optionnel), ville, foyer, téléphone, consentements |
| Foyer | Groupe de membres | ville, membre payeur |
| Role, Permission, Affectation | Droits | affectation = user + rôle + périmètre (association ou ville) |
| TypeContribution | Paramétrage | unité (personne ou foyer), taux de reversement |
| Cotisation | Cotisation périodique | type, montant, période, date d'échéance |
| AppelContribution | Contribution ponctuelle | type, périmètre, montant fixe ou libre, date limite, taux surchargé, statut |
| Echeance | Ce qu'un membre ou foyer doit | débiteur, cotisation ou appel, montant, statut (due, payée, annulée) |
| Paiement | Encaissement | échéance(s), montant, moyen (carte, espèces, virement), identifiant Stripe, statut |
| Repartition | Split d'un paiement | paiement, part ville, part central, identifiant du transfert Stripe |
| Reversement | Virement ville → central (paiements manuels) | ville, montant, date, statut |
| Depense | Sortie d'argent | ville ou central, montant, catégorie, justificatif, statut, validateur |
| Relance | Historique des relances | échéance, canal, date, automatique ou manuelle |
| ImportJob | Suivi d'un import | fichier, correspondance des colonnes, erreurs, statut, possibilité d'annulation |
| AuditLog | Traçabilité | auteur, action, objet, avant / après, date |
| Projet | Projet d'une ville ou de l'association | ville (ou central), titre, description, objectif en centimes, dates, statut ; appels et dépenses liés |

Toutes les tables métier portent `association_id`. Les montants sont stockés **en centimes, dans des entiers**, jamais en décimaux flottants, pour éviter les erreurs d'arrondi.

### Flux de paiement en ligne

1. Le membre choisit ses échéances, et l'app crée une session Stripe Checkout.
2. Stripe encaisse sur le compte de la plateforme.
3. Le webhook `checkout.session.completed` marque les échéances comme payées et crée le `Paiement`.
4. L'app calcule la répartition et crée deux transferts Stripe (« separate charges and transfers ») : un vers la ville, un vers le central.
5. Le reçu PDF est généré et envoyé en asynchrone.

**Décision à prendre :** avec ce mode, la plateforme porte le risque en cas de litige ou de remboursement. L'alternative est d'encaisser directement sur le compte de la ville, puis de transférer la part du central. À trancher en semaine 1, après lecture de la documentation Stripe Connect.

## 7. PRD V1 — User stories et critères d'acceptation

La V1 est réussie si l'association de Moudery peut encaisser ses cotisations de l'année en ligne, suivre ses impayés et présenter son bilan en AG sans tableur à côté.

### Indicateurs de succès (3 mois après la mise en ligne)

- Au moins 60 % des membres actifs ont un compte activé.
- Au moins 50 % des cotisations sont payées en ligne.
- Le rapport d'AG est produit depuis l'app, sans retraitement manuel.
- Aucun incident de fuite de données entre villes.

Ces cibles sont des propositions, à ajuster avec le bureau.

### Epic 1 — Rejoindre l'association (F-01 à F-04)

**US-1.** En tant que membre, je veux m'inscrire moi-même en choisissant ma ville, pour pouvoir payer en ligne.

- [ ] Le formulaire demande nom, prénom, email, téléphone, ville et consentement aux emails.
- [ ] Après inscription, le compte est « en attente » et le membre voit un message d'attente de validation.
- [ ] Le secrétaire et le trésorier de la ville reçoivent un email de notification.
- [ ] Un email déjà utilisé dans l'association est refusé, avec un lien vers « mot de passe oublié ».

**US-2.** En tant que responsable de ville, je veux inviter un membre par email, pour qu'il n'ait pas besoin d'attendre une validation.

- [ ] Le lien d'invitation expire après 7 jours et ne sert qu'une fois.
- [ ] Le compte invité est actif dès que le membre définit son mot de passe.

### Epic 2 — Payer en ligne (F-15 à F-18)

**US-3.** En tant que membre, je veux voir ce que je dois et payer par carte en quelques clics.

- [ ] Le tableau de bord liste les échéances dues, avec montant et date limite.
- [ ] Je peux sélectionner plusieurs échéances et les payer en une seule fois.
- [ ] Après paiement, les échéances passent à « payée » en moins d'une minute (webhook).
- [ ] Je reçois un reçu PDF par email et je le retrouve dans mon historique.
- [ ] Si je ferme la page Stripe sans payer, rien n'est marqué payé.

**US-4.** En tant que bureau central, je veux que ma part soit reversée automatiquement à chaque paiement en ligne.

- [ ] Pour chaque paiement, une `Repartition` est créée avec la part ville et la part central, conformément au taux du type ou de l'appel.
- [ ] Somme des parts = montant payé, au centime près.
- [ ] Un webhook reçu deux fois ne crée pas de doublon (idempotence).

**US-5.** En tant que trésorier, je veux enregistrer un paiement en espèces, pour que les membres sans carte soient aussi à jour.

- [ ] Le paiement manuel marque l'échéance payée et génère un reçu.
- [ ] Le montant dû au central est ajouté au solde « à reverser » de la ville.

### Epic 3 — Contributions ponctuelles (F-13, F-14)

**US-6.** En tant que président de ville ou bureau central, je veux lancer un appel à contribution (par exemple pour un décès), pour collecter rapidement.

- [ ] Je choisis le type, le montant (fixe ou libre), la date limite et, pour une ville, le taux de reversement.
- [ ] À l'ouverture, une échéance est créée pour chaque membre ou foyer concerné et un email est envoyé.
- [ ] Une page de suivi affiche le montant collecté et le nombre de contributeurs.
- [ ] Un appel de ville n'est visible que des membres de cette ville.

### Epic 4 — Dépenses (F-21 à F-23)

**US-7.** En tant que trésorier, je veux soumettre une dépense avec son justificatif, pour qu'elle soit validée.

- [ ] La soumission est impossible sans justificatif.
- [ ] Le président de ville est notifié par email.

**US-8.** En tant que président de ville, je veux valider ou refuser une dépense.

- [ ] Je ne peux pas valider une dépense que j'ai moi-même saisie.
- [ ] Un refus exige un motif, envoyé au trésorier.
- [ ] La validation est tracée (qui, quand) et visible dans l'historique.
- [ ] Le solde de la caisse ne change qu'au statut « payée ».

### Epic 5 — Impayés et relances (F-24, F-25)

**US-9.** En tant que trésorier, je veux que les relances partent seules, et pouvoir relancer à la main.

- [ ] Les relances automatiques suivent le calendrier configuré, et un membre ne reçoit jamais deux fois la même relance le même jour.
- [ ] Le tableau des impayés liste membre, échéance, montant, retard en jours et dernière relance.
- [ ] Je peux relancer une sélection de membres en un clic.
- [ ] Un membre qui a payé ne reçoit plus de relance pour cette échéance.

### Epic 6 — Transparence et rapports (F-26 à F-30)

**US-10.** En tant que membre, je veux voir comment l'argent de ma ville est utilisé.

- [ ] Je vois les totaux d'entrées par type et par mois, sans aucun nom de membre.
- [ ] Je vois la liste des dépenses payées (date, catégorie, montant, libellé) et le solde.

**US-11.** En tant que bureau central, je veux un rapport annuel exportable pour l'AG.

- [ ] Le rapport consolidé et par ville inclut les entrées par type, les dépenses par catégorie, les reversements et les soldes.
- [ ] Export en PDF (mise en page imprimable) et en XLSX.
- [ ] Les chiffres de l'export sont identiques à ceux du tableau de bord.

### Epic 7 — Import des données existantes (F-31)

**US-12.** En tant que trésorier, je veux importer mon fichier Excel de membres et de paiements, pour ne pas tout ressaisir.

- [ ] J'associe chaque colonne de mon fichier à un champ de l'app.
- [ ] Un aperçu signale les erreurs ligne par ligne (email invalide, doublon, ville inconnue) avant tout enregistrement.
- [ ] Je peux importer uniquement les lignes valides et télécharger les lignes en erreur.
- [ ] Je peux annuler un import complet tant qu'aucun paiement en ligne n'a été rattaché aux données importées.

### Epic 8 — Graphiques et tableaux de bord (F-32 à F-39)

**US-13.** En tant que bureau central, je veux des tableaux de bord graphiques interactifs, pour piloter toutes les villes et convaincre en AG.

- [ ] Les 12 graphiques du catalogue M10 s'affichent selon le rôle et le périmètre de l'utilisateur.
- [ ] Changer un filtre (période, ville, type) met à jour tous les graphiques de la page en moins de 1 seconde.
- [ ] Cliquer sur une barre ouvre la liste des mouvements correspondants.
- [ ] Pour chaque graphique, le total affiché est identique à celui du tableau de bord chiffré et de l'export XLSX.
- [ ] Chaque graphique s'exporte en PNG et apparaît dans le PDF d'AG.
- [ ] Sur un écran de 375 px de large, aucun libellé n'est coupé ni superposé.
- [ ] Un membre ne voit aucun graphique contenant des noms ou les données d'une autre ville.

### Epic 9 — Créer la caisse d'une ville (F-40 à F-46)

**US-14.** En tant que bureau central, je veux créer une ville et construire ses données dans un assistant, pour qu'elle soit opérationnelle sans ressaisie.

- [ ] L'assistant enchaîne les étapes : identité, compte bancaire, membres, cotisations, projets et appels, historique, récapitulatif.
- [ ] Un nom de ville déjà utilisé dans l'association est refusé.
- [ ] Je peux quitter l'assistant à n'importe quelle étape et le reprendre plus tard sans rien perdre.
- [ ] Tant que la ville est en brouillon, aucun email n'est envoyé et les membres importés ne voient rien.
- [ ] À l'activation, les membres reçoivent leur invitation et leurs échéances sont générées.
- [ ] Le trésorier invité peut reprendre l'assistant à partir de l'étape Membres.

## 8. Planning, découpage V1 / V2, risques

Le périmètre V1 tient en 8 semaines seulement si rien ne dérape. Le planning garde donc une semaine tampon, et prévoit les briques à décaler en V1.1 si le retard dépasse une semaine.

### Planning sur 8 semaines

| Semaine | Contenu | Livrable |
| --- | --- | --- |
| S1 | Mise en place du projet, Docker, CI, entités de base, filtre multi-tenant et ses tests, authentification. Choix du mode Stripe Connect. | Connexion fonctionnelle, isolation testée |
| S2 | Villes et assistant de création d'une ville, rôles et Voters, membres, foyers, inscription et invitation. | Gestion des membres complète |
| S3 | Types de contribution, cotisations, génération des échéances, appels ponctuels. | Échéances générées |
| S4 | Stripe : onboarding des villes, Checkout, webhooks, répartition, reçus PDF. | Premier paiement réel en mode test |
| S5 | Paiements manuels, reversements, remboursements, dépenses et leur workflow de validation. | Flux d'argent complet |
| S6 | Relances automatiques et manuelles, espace membre, vue de transparence, import CSV / XLSX. | Parcours membre complet |
| S7 | StatsService, les 12 graphiques ECharts avec filtres et interactivité, exports XLSX et PDF d'AG avec graphiques. | Tableaux de bord graphiques et rapports |
| S8 | Tampon, recette avec 2 ou 3 trésoriers, corrections, pages RGPD, mise en production. | V1 en ligne |

### Si le retard dépasse une semaine : à décaler en V1.1

1. L'import avec annulation complète : on garde un import simple des membres seuls.
2. Le PDF d'AG mis en page : on garde l'export XLSX.
3. Le transfert de membre entre villes.
4. Les graphiques les plus complexes (G-02 Sankey, G-03 carte, G-10 heatmap) : on garde les 9 autres, qui couvrent déjà le pilotage.

Le paiement, le multi-tenant et les droits ne se simplifient pas : ce sont eux qu'il faut protéger.

### V2 (après la mise en ligne)

- Rôles entièrement personnalisables par l'association.
- Prélèvement SEPA et paiements récurrents (Stripe Billing).
- Notifications WhatsApp (WhatsApp Business Platform, templates « utility »), puis SMS.
- Onboarding en libre-service de nouvelles associations et facturation du SaaS.
- Double authentification, si elle n'est pas faite en V1.
- Traduction de l'interface.

### Risques

| Risque | Impact | Parade |
| --- | --- | --- |
| Délai de 8 semaines pour une seule développeuse | Élevé | Semaine tampon, liste de replis ci-dessus, recette dès la S6 plutôt qu'à la fin. |
| Fuite de données entre associations ou villes | Critique | Filtre Doctrine global, Voters, tests automatisés dédiés dès la S1. |
| Complexité de Stripe Connect (splits, remboursements, litiges) | Élevé | Décision d'architecture en S1, tests complets en mode test Stripe, gestion idempotente des webhooks. |
| Trésoriers qui ne terminent pas l'onboarding Stripe | Moyen | Paiements manuels possibles en attendant, accompagnement et guide pas à pas. |
| Fichiers existants très hétérogènes | Moyen | Récupérer des exemples réels dès la S1 pour concevoir l'import. |
| Faible adoption par les membres | Moyen | Parcours de paiement mobile très court, invitations envoyées par les trésoriers. |

### Points ouverts à valider avec l'association

- [ ] Liste des villes et des responsables de chacune.
- [ ] Types de contribution, montants et taux de reversement réels.
- [ ] Qui paie les frais Stripe : la ville, le central, ou les deux au prorata ?
- [x] Le bureau central peut-il valider les dépenses d'une ville en l'absence du président ? Non (décision du 29 septembre 2026) : seul le président de la ville valide.
- [ ] Calendrier des relances souhaité.
- [ ] Durée de conservation des données comptables et des données des anciens membres.
- [ ] Contenu attendu du rapport d'AG (récupérer un rapport des années précédentes).
- [ ] Exemples de fichiers Excel existants pour l'import.
- [ ] Existence juridique de l'association et des villes (nécessaire pour l'onboarding Stripe).
