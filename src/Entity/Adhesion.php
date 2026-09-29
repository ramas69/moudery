<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AdhesionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ce membre était adhérent de sa ville cette année-là, et ce qu'il a versé mois par mois : la ligne du classeur de la
 * ville (F-45). C'est le socle de l'historique, avant que les cotisations et les paiements existent dans l'application :
 * chaque mois porte un montant en centimes, ou le code tel qu'il a été tapé dans le classeur (« V », « X », « M »…),
 * ou rien ; le rapatriement et le projet sont des contributions annuelles à part. Le total, le tarif mensuel et le
 * reste dû se déduisent, ils ne sont jamais importés.
 */
#[ORM\Entity(repositoryClass: AdhesionRepository::class)]
#[ORM\Table(name: 'adhesion')]
#[ORM\UniqueConstraint(name: 'uniq_adhesion_membre_annee', columns: ['membre_id', 'annee'])]
#[ORM\Index(name: 'idx_adhesion_ville_annee', columns: ['ville_id', 'annee'])]
class Adhesion
{
    public const int ANNEE_MINIMALE = 1990;
    public const int MOIS = 12;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Portée par toutes les tables métier, pour le filtre multi-tenant. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Association $association;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Ville $ville;

    #[ORM\ManyToOne(inversedBy: 'adhesions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Membre $membre;

    #[ORM\Column(type: 'smallint')]
    private int $annee;

    /** D'où vient l'adhésion : saisie, import, inscription libre, génération de données d'essai (mêmes valeurs que Membre). */
    #[ORM\Column(length: 20)]
    private string $origine;

    /**
     * Douze cases, de janvier à décembre (indices 0 à 11) : centimes versés (int), code du classeur (string), ou null.
     *
     * @var list<int|string|null>|null null tant qu'aucun historique n'est connu
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $mois = null;

    /** Contribution annuelle à la caisse de rapatriement, en centimes. */
    #[ORM\Column(nullable: true)]
    private ?int $rapatriement = null;

    /** Contribution de l'année aux projets, en centimes (« PROJET 200 € » dans le classeur). */
    #[ORM\Column(nullable: true)]
    private ?int $projet = null;

    /** Le montant mensuel attendu, déduit des versements (le plus fréquent), en centimes. */
    #[ORM\Column(nullable: true)]
    private ?int $tarifMensuel = null;

    #[ORM\Column]
    private \DateTimeImmutable $creeLe;

    /**
     * Les douze mois civils dans l'ordre d'un exercice qui commence au mois `$debut` (1 = janvier) : pour octobre,
     * 10, 11, 12, 1, … 9. Les mois restent rangés par numéro civil dans l'adhésion ; seul l'affichage suit l'exercice.
     *
     * @return list<int>
     */
    public static function ordreDesMois(int $debut): array
    {
        $debut = max(1, min(12, $debut));

        return [...range($debut, 12), ...(1 === $debut ? [] : range(1, $debut - 1))];
    }

    public function __construct(Membre $membre, int $annee, string $origine = Membre::ORIGINE_SAISIE)
    {
        if (!self::anneeValide($annee)) {
            throw new \InvalidArgumentException(\sprintf('L\'année d\'adhésion « %d » n\'est pas plausible.', $annee));
        }
        $this->membre = $membre;
        $this->ville = $membre->getVille();
        $this->association = $membre->getAssociation();
        $this->annee = $annee;
        $this->origine = $origine;
        $this->creeLe = new \DateTimeImmutable();
    }

    /** Entre 1990 et l'année prochaine : au-delà, c'est une faute de saisie ou une autre colonne. */
    public static function anneeValide(int $annee): bool
    {
        return $annee >= self::ANNEE_MINIMALE && $annee <= (int) date('Y') + 1;
    }

    /**
     * Pose la ligne du classeur : les douze mois (clés 1 à 12 ou 0 à 11, centimes, code ou null), le rapatriement et
     * le projet en centimes. Le tarif mensuel est le montant le plus fréquent parmi les mois versés.
     *
     * @param array<int, int|string|null> $mois
     */
    public function definirHistorique(array $mois, ?int $rapatriement = null, ?int $projet = null): void
    {
        $cases = array_fill(0, self::MOIS, null);
        foreach ($mois as $cle => $valeur) {
            $indice = \array_key_exists(0, $mois) ? (int) $cle : (int) $cle - 1;
            if ($indice < 0 || $indice >= self::MOIS) {
                continue;
            }
            if (\is_int($valeur)) {
                $cases[$indice] = max(0, $valeur);
            } elseif (\is_string($valeur) && '' !== trim($valeur)) {
                $cases[$indice] = mb_substr(mb_strtoupper(trim($valeur)), 0, 8);
            }
        }
        $this->mois = $cases;
        $this->rapatriement = null === $rapatriement ? null : max(0, $rapatriement);
        $this->projet = null === $projet ? null : max(0, $projet);

        $frequences = [];
        foreach ($cases as $valeur) {
            if (\is_int($valeur) && $valeur > 0) {
                $frequences[$valeur] = ($frequences[$valeur] ?? 0) + 1;
            }
        }
        arsort($frequences);
        $this->tarifMensuel = [] === $frequences ? null : (int) array_key_first($frequences);
    }

    /**
     * Un mois versé par l'application (paiement enregistré) : la case du classeur se remplit avec le montant ; null
     * l'efface (paiement annulé). Le tarif mensuel connu ne bouge pas.
     */
    public function renseignerMois(int $mois, ?int $montant): void
    {
        if ($mois < 1 || $mois > self::MOIS) {
            throw new \InvalidArgumentException('Le mois va de 1 à 12.');
        }
        $cases = $this->mois ?? array_fill(0, self::MOIS, null);
        $cases[$mois - 1] = null === $montant ? null : max(0, $montant);
        $this->mois = $cases;
    }

    /** Le tarif de la cotisation ouverte pour cette année : ce que chaque mois devrait valoir. */
    public function definirTarifMensuel(?int $tarif): void
    {
        $this->tarifMensuel = null === $tarif ? null : max(0, $tarif);
    }

    /** Ce mois est-il renseigné dans le classeur (montant ou code) ? */
    public function moisRenseigne(int $mois): bool
    {
        return null !== ($this->mois[$mois - 1] ?? null);
    }

    public function aUnHistorique(): bool
    {
        return null !== $this->mois || null !== $this->rapatriement || null !== $this->projet;
    }

    /** @return array<int, int|string|null> clés 1 (janvier) à 12 (décembre) */
    public function getMois(): array
    {
        $parMois = [];
        for ($m = 1; $m <= self::MOIS; ++$m) {
            $parMois[$m] = $this->mois[$m - 1] ?? null;
        }

        return $parMois;
    }

    /** Les centimes versés ce mois-là (1 à 12), ou null si rien n'a été versé ou si la case porte un code. */
    public function getMontantMois(int $mois): ?int
    {
        $valeur = $this->mois[$mois - 1] ?? null;

        return \is_int($valeur) ? $valeur : null;
    }

    /** Le code tapé dans le classeur pour ce mois (« V », « X », « M »…), ou null. */
    public function getCodeMois(int $mois): ?string
    {
        $valeur = $this->mois[$mois - 1] ?? null;

        return \is_string($valeur) ? $valeur : null;
    }

    /** Ce qui a été versé sur l'année, en centimes : la somme des mois, sans le rapatriement ni le projet, comme le « Total » du classeur. */
    public function getTotal(): int
    {
        $total = 0;
        foreach ($this->mois ?? [] as $valeur) {
            if (\is_int($valeur)) {
                $total += $valeur;
            }
        }

        return $total;
    }

    public function getTarifMensuel(): ?int
    {
        return $this->tarifMensuel;
    }

    /** Douze fois le tarif mensuel, ou null quand aucun versement ne permet de le connaître. */
    public function getAttendu(): ?int
    {
        return null === $this->tarifMensuel ? null : $this->tarifMensuel * self::MOIS;
    }

    /** Le « RESTE » du classeur : ce qui manque sur l'année, jamais négatif ; null sans tarif connu. */
    public function getReste(): ?int
    {
        $attendu = $this->getAttendu();

        return null === $attendu ? null : max(0, $attendu - $this->getTotal());
    }

    public function getRapatriement(): ?int
    {
        return $this->rapatriement;
    }

    public function getProjet(): ?int
    {
        return $this->projet;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAssociation(): Association
    {
        return $this->association;
    }

    public function getVille(): Ville
    {
        return $this->ville;
    }

    public function getMembre(): Membre
    {
        return $this->membre;
    }

    public function getAnnee(): int
    {
        return $this->annee;
    }

    public function getOrigine(): string
    {
        return $this->origine;
    }

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }
}
