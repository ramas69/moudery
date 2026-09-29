<?php

declare(strict_types=1);

namespace App\Relance;

use App\Administration\Texte;
use App\Appel\Appels;
use App\Entity\Association;
use App\Entity\AssociationStatut;
use App\Entity\Echeance;
use App\Entity\Membre;
use App\Entity\Relance;
use App\Entity\TypeEvenement;
use App\Entity\Utilisateur;
use App\Entity\Ville;
use App\Journal\Journal;
use App\Paiement\Paiements;
use App\Repository\AssociationRepository;
use App\Repository\EcheanceRepository;
use App\Repository\RelanceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Impayés et relances (F-24, F-25). Le tableau des impayés regroupe, par membre, les échéances dues dont la date
 * limite est passée, et dit d'où elles viennent (la cotisation d'une année, un appel). Une relance, manuelle ou
 * automatique, est un e-mail qui rappelle au membre tout ce qu'il doit ; elle n'est envoyée qu'aux membres joignables
 * (adresse et accord), jamais deux fois le même jour pour le même genre de relance, et jamais pour une échéance déjà
 * payée. Un membre injoignable s'appelle : « Marquer comme appelé » note l'appel sans rien envoyer. Les relances
 * automatiques suivent le calendrier de l'association (jours par rapport à l'échéance) ; un appel sans relances
 * automatiques n'en déclenche aucune.
 *
 * @phpstan-type Source array{cle: string, type: string, libelle: string, dateLimite: ?\DateTimeImmutable, nombre: int, montant: int, plusAncienne: \DateTimeImmutable}
 * @phpstan-type Impaye array{membre: Membre, echeances: list<Echeance>, sources: list<Source>, montant: int, retard: int, plusAncienne: \DateTimeImmutable, derniereRelance: ?Relance, rappels: int, joignable: bool, motif: ?string}
 * @phpstan-type Prochaine array{date: \DateTimeImmutable, jours: int, membres: int, injoignables: int}
 */
final class Relances
{
    public const string FILTRE_TOUS = 'tous';
    public const string FILTRE_PLUS_60 = 'plus_60';
    public const string FILTRE_SANS_EMAIL = 'sans_email';
    public const array FILTRES = [self::FILTRE_TOUS, self::FILTRE_PLUS_60, self::FILTRE_SANS_EMAIL];
    public const string SOURCE_TOUTES = 'toutes';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EcheanceRepository $echeances,
        private readonly RelanceRepository $relances,
        private readonly AssociationRepository $associations,
        private readonly Journal $journal,
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * Les impayés par membre, du retard le plus long au plus court. `rappels` compte les relances (e-mails et appels)
     * reçues depuis que la plus ancienne échéance est due.
     *
     * @return list<Impaye>
     */
    public function impayes(Association $association, ?Ville $ville, \DateTimeImmutable $aujourdhui): array
    {
        $parMembre = [];
        foreach ($this->echeances->impayees($association, $ville, $aujourdhui) as $echeance) {
            $membre = $echeance->getMembre();
            $id = (int) $membre->getId();
            $parMembre[$id] ??= ['membre' => $membre, 'echeances' => [], 'sources' => [], 'montant' => 0, 'retard' => 0, 'plusAncienne' => $echeance->getDateLimite(), 'derniereRelance' => null, 'rappels' => 0, 'joignable' => Appels::joignable($membre), 'motif' => self::motifInjoignable($membre)];
            $parMembre[$id]['echeances'][] = $echeance;
            $parMembre[$id]['montant'] += $echeance->getMontant() ?? 0;
            if ($echeance->getDateLimite() < $parMembre[$id]['plusAncienne']) {
                $parMembre[$id]['plusAncienne'] = $echeance->getDateLimite();
            }
            $source = self::source($echeance);
            $cle = $source['cle'];
            $parMembre[$id]['sources'][$cle] ??= $source + ['nombre' => 0, 'montant' => 0, 'plusAncienne' => $echeance->getDateLimite()];
            ++$parMembre[$id]['sources'][$cle]['nombre'];
            $parMembre[$id]['sources'][$cle]['montant'] += $echeance->getMontant() ?? 0;
            if ($echeance->getDateLimite() < $parMembre[$id]['sources'][$cle]['plusAncienne']) {
                $parMembre[$id]['sources'][$cle]['plusAncienne'] = $echeance->getDateLimite();
            }
        }
        $historique = $this->relances->historiqueParMembre($association, $ville);
        $jour = $aujourdhui->setTime(0, 0);
        foreach ($parMembre as $id => &$impaye) {
            $depuis = $impaye['plusAncienne'];
            $impaye['retard'] = (int) $depuis->diff($jour)->days;
            $impaye['sources'] = array_values($impaye['sources']);
            $relances = $historique[$id] ?? [];
            $impaye['derniereRelance'] = [] === $relances ? null : $relances[array_key_last($relances)];
            $impaye['rappels'] = \count(array_filter($relances, static fn (Relance $r): bool => $r->getJour() >= $depuis));
        }
        unset($impaye);
        $lignes = array_values($parMembre);
        usort($lignes, static fn (array $a, array $b): int => $b['retard'] <=> $a['retard'] ?: $b['montant'] <=> $a['montant']);

        return $lignes;
    }

    /** Pourquoi un membre ne peut pas être relancé par e-mail : `sans_email`, `sans_consentement`, ou null s'il est joignable. */
    public static function motifInjoignable(Membre $membre): ?string
    {
        if (Appels::joignable($membre)) {
            return null;
        }

        return null === $membre->getEmail() || '' === $membre->getEmail() ? 'sans_email' : 'sans_consentement';
    }

    /**
     * D'où vient une échéance : la cotisation d'une année ou un appel, sous une clé commune aux échéances de même origine.
     *
     * @return array{cle: string, type: string, libelle: string, dateLimite: ?\DateTimeImmutable}
     */
    public static function source(Echeance $echeance): array
    {
        $appel = $echeance->getAppel();
        if (null !== $appel) {
            return ['cle' => 'appel:'.$appel->getId(), 'type' => 'appel', 'libelle' => $appel->getObjet(), 'dateLimite' => $appel->getDateLimite()];
        }
        $cotisation = $echeance->getCotisation();
        if (null === $cotisation) {
            return ['cle' => 'autre', 'type' => 'autre', 'libelle' => '', 'dateLimite' => null];
        }

        return ['cle' => 'cotisation:'.$cotisation->getId(), 'type' => 'cotisation', 'libelle' => \sprintf('%s %d', $cotisation->getType()->getNom(), $cotisation->getAnnee()), 'dateLimite' => null];
    }

    /**
     * Les origines présentes dans les impayés (pour le sélecteur « Échéance »), avec le nombre de membres concernés.
     *
     * @param list<Impaye> $impayes
     *
     * @return array<string, array{libelle: string, membres: int}> clé de source => libellé et membres
     */
    public static function sources(array $impayes): array
    {
        $sources = [];
        foreach ($impayes as $impaye) {
            foreach ($impaye['sources'] as $source) {
                $sources[$source['cle']] ??= ['libelle' => $source['libelle'], 'membres' => 0];
                ++$sources[$source['cle']]['membres'];
            }
        }
        uasort($sources, static fn (array $a, array $b): int => strcmp(Texte::normaliser($a['libelle']), Texte::normaliser($b['libelle'])));

        return $sources;
    }

    /**
     * Le tableau filtré : tous, plus de 60 jours ou sans e-mail ; une recherche sur le nom ou l'adresse ; une origine.
     *
     * @param list<Impaye> $impayes
     *
     * @return list<Impaye>
     */
    public static function filtrer(array $impayes, string $filtre, string $recherche, string $source): array
    {
        $aiguille = Texte::normaliser($recherche);

        return array_values(array_filter($impayes, static function (array $impaye) use ($filtre, $aiguille, $source): bool {
            if (self::FILTRE_PLUS_60 === $filtre && $impaye['retard'] <= 60) {
                return false;
            }
            if (self::FILTRE_SANS_EMAIL === $filtre && $impaye['joignable']) {
                return false;
            }
            if (self::SOURCE_TOUTES !== $source && !\in_array($source, array_column($impaye['sources'], 'cle'), true)) {
                return false;
            }

            return '' === $aiguille || str_contains(Texte::normaliser($impaye['membre']->getNomComplet().' '.(string) $impaye['membre']->getEmail()), $aiguille);
        }));
    }

    /**
     * @param list<Impaye> $impayes
     *
     * @return array{tous: int, plus_60: int, sans_email: int}
     */
    public static function compteurs(array $impayes): array
    {
        return [
            self::FILTRE_TOUS => \count($impayes),
            self::FILTRE_PLUS_60 => \count(array_filter($impayes, static fn (array $i): bool => $i['retard'] > 60)),
            self::FILTRE_SANS_EMAIL => \count(array_filter($impayes, static fn (array $i): bool => !$i['joignable'])),
        ];
    }

    /**
     * Les impayés par ancienneté (G-06) : 0 à 30 jours, 31 à 60, plus de 60. Chaque échéance compte selon son propre
     * retard, en centimes et en nombre ; les membres d'une tranche sont ceux qui y ont au moins une échéance (un même
     * membre peut donc figurer dans plusieurs tranches : la ligne se lit « N membres doivent X € depuis 31 à 60 jours »).
     *
     * @param list<Impaye> $impayes
     *
     * @return array<string, array{montant: int, echeances: int, membres: int}>
     */
    public static function anciennete(array $impayes, \DateTimeImmutable $aujourdhui): array
    {
        $vide = ['montant' => 0, 'echeances' => 0, 'membres' => 0];
        $tranches = ['0_30' => $vide, '31_60' => $vide, '61' => $vide];
        $jour = $aujourdhui->setTime(0, 0);
        foreach ($impayes as $impaye) {
            $vues = [];
            foreach ($impaye['echeances'] as $echeance) {
                $cle = self::tranche((int) $echeance->getDateLimite()->diff($jour)->days);
                $tranches[$cle]['montant'] += $echeance->getMontant() ?? 0;
                ++$tranches[$cle]['echeances'];
                if (!isset($vues[$cle])) {
                    $vues[$cle] = true;
                    ++$tranches[$cle]['membres'];
                }
            }
        }

        return $tranches;
    }

    private static function tranche(int $retard): string
    {
        return $retard <= 30 ? '0_30' : ($retard <= 60 ? '31_60' : '61');
    }

    /**
     * Relance manuelle (F-25) : un e-mail par membre choisi, avec tout ce qu'il doit. Ignorés : sans échéance en
     * retard, injoignables, déjà relancés à la main aujourd'hui.
     *
     * @param list<Membre> $membres
     *
     * @return array{envoyees: int, ignores: int}
     */
    public function relancer(array $membres, ?Utilisateur $acteur, \DateTimeImmutable $quand): array
    {
        $envoyees = 0;
        $ignores = 0;
        foreach ($membres as $membre) {
            $dues = $this->echeances->duesPourMembre($membre);
            $enRetard = array_filter($dues, static fn (Echeance $e): bool => $e->estEnRetard($quand));
            if ([] === $enRetard || !Appels::joignable($membre) || $this->relances->dejaRelanceLe($membre, $quand, false)) {
                ++$ignores;
                continue;
            }
            $this->envoyer($membre, $dues, false, $acteur, $quand);
            ++$envoyees;
        }
        $this->entityManager->flush();

        return ['envoyees' => $envoyees, 'ignores' => $ignores];
    }

    /**
     * « Marquer comme appelé » : le trésorier a joint le membre (sans e-mail, ou de vive voix) ; l'appel est noté
     * comme une relance par téléphone, sur les échéances en retard, sans rien envoyer. Jamais deux fois le même jour.
     *
     * @param list<Membre> $membres
     *
     * @return array{marques: int, ignores: int}
     */
    public function marquerAppeles(array $membres, ?Utilisateur $acteur, \DateTimeImmutable $quand): array
    {
        $marques = 0;
        $ignores = 0;
        foreach ($membres as $membre) {
            $enRetard = array_values(array_filter($this->echeances->duesPourMembre($membre), static fn (Echeance $e): bool => $e->estEnRetard($quand)));
            if ([] === $enRetard || $this->relances->dejaRelanceLe($membre, $quand, false, Relance::CANAL_TELEPHONE)) {
                ++$ignores;
                continue;
            }
            $relance = new Relance($membre, $enRetard, false, $acteur, $quand, Relance::CANAL_TELEPHONE);
            $this->entityManager->persist($relance);
            $this->journal->consigner(TypeEvenement::MembreAppele, $acteur, $membre->getAssociation(), $membre->getNomComplet(), [
                'ville' => $membre->getVille()->getNom(),
                'membre' => $membre->getNomComplet(),
                'montant' => $relance->getMontant(),
                'echeances' => array_map(static fn (Echeance $e): array => Paiements::libelle($e), $enRetard),
            ], $quand);
            ++$marques;
        }
        $this->entityManager->flush();

        return ['marques' => $marques, 'ignores' => $ignores];
    }

    /**
     * Les prochains envois automatiques du périmètre : pour chaque échéance due, les dates du calendrier de
     * l'association (J-7, J, J+15…) qui ne sont pas passées ; par date, les membres joignables et les autres.
     * Un appel sans relances automatiques ne compte pas.
     *
     * @return list<Prochaine> de la plus proche à la plus lointaine, `$limite` au plus
     */
    public function prochainesAutomatiques(Association $association, ?Ville $ville, \DateTimeImmutable $aujourdhui, int $limite = 3): array
    {
        $calendrier = $association->getCalendrierRelances();
        if ([] === $calendrier) {
            return [];
        }
        $jour = $aujourdhui->setTime(0, 0);
        // Une échéance ne compte que si l'une de ses dates de relance est encore à venir : date limite ≥ aujourd'hui − le plus grand décalage.
        $depuis = $jour->modify(\sprintf('%+d days', -max($calendrier)));
        $parDate = [];
        foreach ($this->echeances->duesPourPerimetre($association, $ville, $depuis) as $echeance) {
            $appel = $echeance->getAppel();
            if (null !== $appel && !$appel->aDesRelancesAuto()) {
                continue;
            }
            $membre = $echeance->getMembre();
            foreach ($calendrier as $decalage) {
                $date = $echeance->getDateLimite()->modify(\sprintf('%+d days', $decalage));
                if ($date < $jour) {
                    continue;
                }
                $cle = $date->format('Y-m-d');
                $parDate[$cle] ??= ['date' => $date, 'jours' => $decalage, 'membres' => [], 'joignables' => []];
                $parDate[$cle]['membres'][(int) $membre->getId()] = true;
                if (Appels::joignable($membre)) {
                    $parDate[$cle]['joignables'][(int) $membre->getId()] = true;
                }
            }
        }
        ksort($parDate);
        $prochaines = [];
        foreach (\array_slice($parDate, 0, $limite) as $prochaine) {
            $prochaines[] = ['date' => $prochaine['date'], 'jours' => $prochaine['jours'], 'membres' => \count($prochaine['joignables']), 'injoignables' => \count($prochaine['membres']) - \count($prochaine['joignables'])];
        }

        return $prochaines;
    }

    /**
     * Les relances automatiques du jour (F-24), pour chaque association active : une échéance due dont la date limite
     * est à J-7, J ou J+15 (le calendrier de l'association) déclenche un e-mail au membre, qui liste tout ce qu'il
     * doit. Un appel sans relances automatiques ne déclenche rien. Jamais deux fois le même jour.
     *
     * @return array{associations: int, envoyees: int, ignores: int}
     */
    public function automatiques(\DateTimeImmutable $jour, bool $simuler = false): array
    {
        $jour = $jour->setTime(0, 0);
        $bilan = ['associations' => 0, 'envoyees' => 0, 'ignores' => 0];
        foreach ($this->associations->listerParNom() as $association) {
            if (AssociationStatut::Active !== $association->getStatut()) {
                continue;
            }
            ++$bilan['associations'];
            $dates = array_map(static fn (int $jours): \DateTimeImmutable => $jour->modify(\sprintf('%+d days', -$jours)), $association->getCalendrierRelances());
            $membres = [];
            foreach ($this->echeances->duesAuxDates($association, $dates) as $echeance) {
                $appel = $echeance->getAppel();
                if (null !== $appel && !$appel->aDesRelancesAuto()) {
                    continue;
                }
                $membres[(int) $echeance->getMembre()->getId()] = $echeance->getMembre();
            }
            foreach ($membres as $membre) {
                if (!Appels::joignable($membre) || $this->relances->dejaRelanceLe($membre, $jour, true)) {
                    ++$bilan['ignores'];
                    continue;
                }
                if (!$simuler) {
                    $this->envoyer($membre, $this->echeances->duesPourMembre($membre), true, null, $jour->setTime((int) date('H'), (int) date('i')));
                }
                ++$bilan['envoyees'];
            }
        }
        if (!$simuler) {
            $this->entityManager->flush();
        }

        return $bilan;
    }

    /** @param list<Echeance> $echeances */
    private function envoyer(Membre $membre, array $echeances, bool $automatique, ?Utilisateur $acteur, \DateTimeImmutable $quand): void
    {
        $relance = new Relance($membre, $echeances, $automatique, $acteur, $quand);
        $this->entityManager->persist($relance);
        $this->mailer->send((new TemplatedEmail())
            ->to(new Address((string) $membre->getEmail(), $membre->getNomComplet()))
            ->subject(\sprintf('%s · rappel de cotisation', $membre->getAssociation()->getNom()))
            ->htmlTemplate('emails/relance.html.twig')
            ->textTemplate('emails/relance.txt.twig')
            ->context([
                'prenom' => $membre->getPrenom(),
                'association' => $membre->getAssociation(),
                'ville' => $membre->getVille(),
                'echeances' => $echeances,
                'montant' => $relance->getMontant(),
                'automatique' => $automatique,
                'aujourdhui' => $quand->setTime(0, 0),
                'lien' => $this->urls->generate('connexion', [], UrlGeneratorInterface::ABSOLUTE_URL),
            ]));
        $this->journal->consigner(TypeEvenement::RelanceEnvoyee, $acteur, $membre->getAssociation(), $membre->getNomComplet(), [
            'ville' => $membre->getVille()->getNom(),
            'membre' => $membre->getNomComplet(),
            'automatique' => $automatique,
            'montant' => $relance->getMontant(),
            'echeances' => array_map(static fn (Echeance $e): array => Paiements::libelle($e), $echeances),
        ], $quand);
    }
}
