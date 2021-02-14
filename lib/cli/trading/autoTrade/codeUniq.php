<?php
namespace cryptos\cli\trading\autoTrade;

/**
 * Chiffrement : création d'un code unique alphanumérique
 *
 * Code : 018ABCDEFG -> 0 + annee '18' + 7 lettres
 * 1 chance sur mille de trouver un code valide
 * Plus de 8 000 000 de codes uniques / année
 *
 * @author Daniel Gomes
 */
class codeUniq
{
    /**
     * Une chance sur x de tomber sur un code valide
     */
    private $_chance;


    /**
     * Constructeur
     */
    public function __construct($chance = 1000)
    {
        $this->_chance = $chance;
    }


    /**
     * Encodage d'une valeur numérique en alpha
     */
    public function encodeAlpha($nombre)
    {
        // Sécurité : on multiplie le nombre par le facteur "chance" -> 1 chance / 1000 d'avoir un code valide
        $nombre = $nombre * $this->_chance;

        // On récupère l'année
        $annee = '0' . date("y");

        // On converti le chiffre de base 10 en base 26 pour passer en alpha
    	$result_0 = floor($nombre/pow(26,6));
    	$diff_0	  = $nombre-($result_0*pow(26,6));

    	$result_1 = floor($diff_0/pow(26,5));
    	$diff_1	  = $diff_0-($result_1*pow(26,5));

    	$result_2 = floor($diff_1/pow(26,4));
    	$diff_2	  = $diff_1-($result_2*pow(26,4));

    	$result_3 = floor($diff_2/pow(26,3));
    	$diff_3	  = $diff_2-($result_3*pow(26,3));

    	$result_4 = floor($diff_3/pow(26,2));
    	$diff_4	  = $diff_3-($result_4*pow(26,2));

    	$result_5 = floor($diff_4/pow(26,1));
    	$diff_5	  = $diff_4-($result_5*pow(26,1));

    	$result_6 = floor($diff_5/pow(26,0));
    	$diff_6	  = $diff_5-($result_6*pow(26,0));

    	// On converti en alpha [0-25] => [A-Z]
    	$alpha	  = $this->recupAlpha($result_0) .
                    $this->recupAlpha($result_1) .
                    $this->recupAlpha($result_2) .
                    $this->recupAlpha($result_3) .
                    $this->recupAlpha($result_4) .
                    $this->recupAlpha($result_5) .
                    $this->recupAlpha($result_6);

    	// On créé un décalage différent pour chacune des lettre comprise en 1 et 6
    	$ltr_1 = $this->recupAlpha1(2,  $this->recupDec(substr($alpha, 0, 1)));
    	$ltr_2 = $this->recupAlpha1(4,  $this->recupDec(substr($alpha, 1, 1)));
    	$ltr_3 = $this->recupAlpha1(6,  $this->recupDec(substr($alpha, 2, 1)));
    	$ltr_4 = $this->recupAlpha1(8,  $this->recupDec(substr($alpha, 3, 1)));
    	$ltr_5 = $this->recupAlpha1(10, $this->recupDec(substr($alpha, 4, 1)));
    	$ltr_6 = $this->recupAlpha1(12, $this->recupDec(substr($alpha, 5, 1)));

    	// On récupère la somme des chiffres de la référence article et on les ajoute à la 7ème lettre
    	$valeur_ref = ord(strtoupper(substr($annee, 0, 1))) +
                      ord(strtoupper(substr($annee, 1, 1))) +
                      ord(strtoupper(substr($annee, 2, 1))) - (48 * 3);

        $ltr_7 = $this->recupAlpha1($valeur_ref, $this->recupDec(substr($alpha, 6, 1)));

    	// en se basant sur la 7ème lettre (et donc la référence), on melange les 6 premières lettres
    	switch ($ltr_7)
    	{
    		case 'A'  : $pre_alpha = $ltr_5.$ltr_3.$ltr_1.$ltr_2.$ltr_4.$ltr_6;	break;
    		case 'B'  : $pre_alpha = $ltr_3.$ltr_1.$ltr_2.$ltr_4.$ltr_6.$ltr_5;	break;
    		case 'C'  : $pre_alpha = $ltr_1.$ltr_2.$ltr_4.$ltr_6.$ltr_5.$ltr_3;	break;
    		case 'D'  : $pre_alpha = $ltr_2.$ltr_4.$ltr_6.$ltr_5.$ltr_3.$ltr_1;	break;
    		case 'E'  : $pre_alpha = $ltr_4.$ltr_6.$ltr_5.$ltr_3.$ltr_1.$ltr_2;	break;

    		case 'F'  : $pre_alpha = $ltr_5.$ltr_1.$ltr_3.$ltr_2.$ltr_4.$ltr_6;	break;
    		case 'G'  : $pre_alpha = $ltr_1.$ltr_3.$ltr_2.$ltr_4.$ltr_6.$ltr_5;	break;
    		case 'H'  : $pre_alpha = $ltr_3.$ltr_2.$ltr_4.$ltr_6.$ltr_5.$ltr_1;	break;
    		case 'I'  : $pre_alpha = $ltr_2.$ltr_4.$ltr_6.$ltr_5.$ltr_1.$ltr_3;	break;
    		case 'J'  : $pre_alpha = $ltr_4.$ltr_6.$ltr_5.$ltr_1.$ltr_3.$ltr_2;	break;

    		case 'K'  : $pre_alpha = $ltr_5.$ltr_2.$ltr_1.$ltr_3.$ltr_4.$ltr_6;	break;
    		case 'L'  : $pre_alpha = $ltr_2.$ltr_1.$ltr_3.$ltr_4.$ltr_6.$ltr_5;	break;
    		case 'M'  : $pre_alpha = $ltr_1.$ltr_3.$ltr_4.$ltr_6.$ltr_5.$ltr_2;	break;
    		case 'N'  : $pre_alpha = $ltr_3.$ltr_4.$ltr_6.$ltr_5.$ltr_2.$ltr_1;	break;
    		case 'O'  : $pre_alpha = $ltr_4.$ltr_6.$ltr_5.$ltr_2.$ltr_1.$ltr_3;	break;

    		case 'P'  : $pre_alpha = $ltr_5.$ltr_4.$ltr_3.$ltr_1.$ltr_2.$ltr_6;	break;
    		case 'Q'  : $pre_alpha = $ltr_4.$ltr_3.$ltr_1.$ltr_2.$ltr_6.$ltr_5;	break;
    		case 'R'  : $pre_alpha = $ltr_3.$ltr_1.$ltr_2.$ltr_6.$ltr_5.$ltr_4;	break;
    		case 'S'  : $pre_alpha = $ltr_1.$ltr_2.$ltr_6.$ltr_5.$ltr_4.$ltr_3;	break;
    		case 'T'  : $pre_alpha = $ltr_2.$ltr_6.$ltr_5.$ltr_4.$ltr_3.$ltr_1;	break;

    		case 'U'  : $pre_alpha = $ltr_5.$ltr_4.$ltr_2.$ltr_1.$ltr_3.$ltr_6;	break;
    		case 'V'  : $pre_alpha = $ltr_4.$ltr_2.$ltr_1.$ltr_3.$ltr_6.$ltr_5;	break;
    		case 'W'  : $pre_alpha = $ltr_2.$ltr_1.$ltr_3.$ltr_6.$ltr_5.$ltr_4;	break;
    		case 'X'  : $pre_alpha = $ltr_1.$ltr_3.$ltr_6.$ltr_5.$ltr_4.$ltr_2;	break;
    		case 'Y'  : $pre_alpha = $ltr_3.$ltr_6.$ltr_5.$ltr_4.$ltr_2.$ltr_1;	break;

    		case 'Z'  : $pre_alpha = $ltr_5.$ltr_2.$ltr_4.$ltr_3.$ltr_1.$ltr_6;	break;
    	}

    	// le code crypté peut maintenant être retourné
    	return $annee . strtolower($pre_alpha . $ltr_7);
    }


    /**
     * Encodage d'une valeur numérique
     */
    public function decodeAlpha($code)
    {
    	$annee = substr($code, 0, 3);
        $alpha = strtoupper(substr($code, 3, 7));

        // Vérifie la validité du code
        if (    strlen($code) != 10
            ||  !is_numeric($annee)
            ||  !ctype_alpha($alpha)
            ||  intval(substr($annee, 1, 2)) < 18
            ||  intval(substr($annee, 1, 2)) > date('y')) {

            return false;
        }

    	// On récupère les 7 lettre de la partie alpha
    	$ltr_1 = substr($alpha, 0, 1);
    	$ltr_2 = substr($alpha, 1, 1);
    	$ltr_3 = substr($alpha, 2, 1);
    	$ltr_4 = substr($alpha, 3, 1);
    	$ltr_5 = substr($alpha, 4, 1);
    	$ltr_6 = substr($alpha, 5, 1);
    	$ltr_7 = substr($alpha, 6, 1);

    	// En fonction de la 7ème lettre, on remet les 6 premières lettres dans l'ordre
    	switch ($ltr_7)
    	{
    		case 'A'  : $pre_alpha = $ltr_3.$ltr_4.$ltr_2.$ltr_5.$ltr_1.$ltr_6;	break;
    		case 'B'  : $pre_alpha = $ltr_2.$ltr_3.$ltr_1.$ltr_4.$ltr_6.$ltr_5;	break;
    		case 'C'  : $pre_alpha = $ltr_1.$ltr_2.$ltr_6.$ltr_3.$ltr_5.$ltr_4;	break;
    		case 'D'  : $pre_alpha = $ltr_6.$ltr_1.$ltr_5.$ltr_2.$ltr_4.$ltr_3;	break;
    		case 'E'  : $pre_alpha = $ltr_5.$ltr_6.$ltr_4.$ltr_1.$ltr_3.$ltr_2;	break;

    		case 'F'  : $pre_alpha = $ltr_2.$ltr_4.$ltr_3.$ltr_5.$ltr_1.$ltr_6;	break;
    		case 'G'  : $pre_alpha = $ltr_1.$ltr_3.$ltr_2.$ltr_4.$ltr_6.$ltr_5;	break;
    		case 'H'  : $pre_alpha = $ltr_6.$ltr_2.$ltr_1.$ltr_3.$ltr_5.$ltr_4;	break;
    		case 'I'  : $pre_alpha = $ltr_5.$ltr_1.$ltr_6.$ltr_2.$ltr_4.$ltr_3;	break;
    		case 'J'  : $pre_alpha = $ltr_4.$ltr_6.$ltr_5.$ltr_1.$ltr_3.$ltr_2;	break;

    		case 'K'  : $pre_alpha = $ltr_3.$ltr_2.$ltr_4.$ltr_5.$ltr_1.$ltr_6;	break;
    		case 'L'  : $pre_alpha = $ltr_2.$ltr_1.$ltr_3.$ltr_4.$ltr_6.$ltr_5;	break;
    		case 'M'  : $pre_alpha = $ltr_1.$ltr_6.$ltr_2.$ltr_3.$ltr_5.$ltr_4;	break;
    		case 'N'  : $pre_alpha = $ltr_6.$ltr_5.$ltr_1.$ltr_2.$ltr_4.$ltr_3;	break;
    		case 'O'  : $pre_alpha = $ltr_5.$ltr_4.$ltr_6.$ltr_1.$ltr_3.$ltr_2;	break;

    		case 'P'  : $pre_alpha = $ltr_4.$ltr_5.$ltr_3.$ltr_2.$ltr_1.$ltr_6;	break;
    		case 'Q'  : $pre_alpha = $ltr_3.$ltr_4.$ltr_2.$ltr_1.$ltr_6.$ltr_5;	break;
    		case 'R'  : $pre_alpha = $ltr_2.$ltr_3.$ltr_1.$ltr_6.$ltr_5.$ltr_4;	break;
    		case 'S'  : $pre_alpha = $ltr_1.$ltr_2.$ltr_6.$ltr_5.$ltr_4.$ltr_3;	break;
    		case 'T'  : $pre_alpha = $ltr_6.$ltr_1.$ltr_5.$ltr_4.$ltr_3.$ltr_2;	break;

    		case 'U'  : $pre_alpha = $ltr_4.$ltr_3.$ltr_5.$ltr_2.$ltr_1.$ltr_6;	break;
    		case 'V'  : $pre_alpha = $ltr_3.$ltr_2.$ltr_4.$ltr_1.$ltr_6.$ltr_5;	break;
    		case 'W'  : $pre_alpha = $ltr_2.$ltr_1.$ltr_3.$ltr_6.$ltr_5.$ltr_4;	break;
    		case 'X'  : $pre_alpha = $ltr_1.$ltr_6.$ltr_2.$ltr_5.$ltr_4.$ltr_3;	break;
    		case 'Y'  : $pre_alpha = $ltr_6.$ltr_5.$ltr_1.$ltr_4.$ltr_3.$ltr_2;	break;

    		case 'Z'  : $pre_alpha = $ltr_5.$ltr_2.$ltr_4.$ltr_3.$ltr_1.$ltr_6;	break;
    	}

    	// On remet les lettres de 1 à 6 en face des bonne lettres
    	$ltr_1 = $this->recupAlpha1(-2,  $this->recupDec(substr($pre_alpha, 0, 1)));
    	$ltr_2 = $this->recupAlpha1(-4,  $this->recupDec(substr($pre_alpha, 1, 1)));
    	$ltr_3 = $this->recupAlpha1(-6,  $this->recupDec(substr($pre_alpha, 2, 1)));
    	$ltr_4 = $this->recupAlpha1(-8,  $this->recupDec(substr($pre_alpha, 3, 1)));
    	$ltr_5 = $this->recupAlpha1(-10, $this->recupDec(substr($pre_alpha, 4, 1)));
    	$ltr_6 = $this->recupAlpha1(-12, $this->recupDec(substr($pre_alpha, 5, 1)));

    	// On récupère la somme des chiffres de la référence article et on la soustrait de la 7ème lettre
    	$valeur_ref = ord(strtoupper(substr($annee, 0, 1))) +
                      ord(strtoupper(substr($annee, 1, 1))) +
                      ord(strtoupper(substr($annee, 2, 1))) - (48 * 3);
        $ltr_7 = $this->recupAlpha1((-$valeur_ref), $this->recupDec(substr($alpha, 6, 1)));

    	$ltr_1 = $this->recupDec($ltr_1);
    	$ltr_2 = $this->recupDec($ltr_2);
    	$ltr_3 = $this->recupDec($ltr_3);
    	$ltr_4 = $this->recupDec($ltr_4);
    	$ltr_5 = $this->recupDec($ltr_5);
    	$ltr_6 = $this->recupDec($ltr_6);
    	$ltr_7 = $this->recupDec($ltr_7);

    	// On reconverti les lettres en chiffre pour récupérer la valeur avant cryptage
        $nombre =   ($ltr_1 * pow(26,6)) +
                    ($ltr_2 * pow(26,5)) +
                    ($ltr_3 * pow(26,4)) +
                    ($ltr_4 * pow(26,3)) +
                    ($ltr_5 * pow(26,2)) +
                    ($ltr_6 * pow(26,1)) +
                    ($ltr_7 * pow(26,0));

        // Sécurité : on divise le nombre par le facteur "chance" -> 1 chance / 1000 d'avoir un code valide
        $nombre = $nombre / $this->_chance;

        if (!is_int($nombre)) {
            return false;
        }

    	return $nombre;
	}


    /**
     * Générateur de liste de codes valides
     */
    public function listeEncodeAlpha($debut_plage, $fin_plage)
	{
    	$debut_plage = $debut_plage;
    	$fin_plage   = $fin_plage;

    	$liste = '';

    	while ($debut_plage <= $fin_plage) {
        	$liste .= $this->encodeAlpha($debut_plage) . "\r\n";
    		$debut_plage++;
    	}

    	return $liste;
	}


    /**
     * Conversion décimale vers alpha (base 10 => base 26)
     */
    function dec_alpha($chiffre)
    {
    	$result_0 = floor($chiffre/pow(26,6));
    	$diff_0	  = $chiffre-($result_0*pow(26,6));

    	$result_1 = floor($diff_0/pow(26,5));
    	$diff_1	  = $diff_0-($result_1*pow(26,5));

    	$result_2 = floor($diff_1/pow(26,4));
    	$diff_2	  = $diff_1-($result_2*pow(26,4));

    	$result_3 = floor($diff_2/pow(26,3));
    	$diff_3	  = $diff_2-($result_3*pow(26,3));

    	$result_4 = floor($diff_3/pow(26,2));
    	$diff_4	  = $diff_3-($result_4*pow(26,2));

    	$result_5 = floor($diff_4/pow(26,1));
    	$diff_5	  = $diff_4-($result_5*pow(26,1));

    	$result_6 = floor($diff_5/pow(26,0));
    	$diff_6	  = $diff_5-($result_6*pow(26,0));

        $alpha = $this->recupAlpha($result_0) .
                 $this->recupAlpha($result_1) .
                 $this->recupAlpha($result_2) .
                 $this->recupAlpha($result_3) .
                 $this->recupAlpha($result_4) .
                 $this->recupAlpha($result_5) .
                 $this->recupAlpha($result_6);

        return $alpha;
    }


    /**
     * Conversion alpha vers décimale (base 26 => base 10)
     */
    function alpha_dec($code)
    {
    	$lettre_1 = $this->recupDec(substr($code, 0, 1));
    	$lettre_2 = $this->recupDec(substr($code, 1, 1));
    	$lettre_3 = $this->recupDec(substr($code, 2, 1));
    	$lettre_4 = $this->recupDec(substr($code, 3, 1));
    	$lettre_5 = $this->recupDec(substr($code, 4, 1));
    	$lettre_6 = $this->recupDec(substr($code, 5, 1));
    	$lettre_7 = $this->recupDec(substr($code, 6, 1));

    	$nombre	  = ($lettre_1 * pow(26,6)) +
                    ($lettre_2 * pow(26,5)) +
                    ($lettre_3 * pow(26,4)) +
                    ($lettre_4 * pow(26,3)) +
                    ($lettre_5 * pow(26,2)) +
                    ($lettre_6 * pow(26,1)) +
                    ($lettre_7 * pow(26,0));

    	return $nombre;
    }


    /**
     * Conversion chiffre vers lettre [0-25] => [A-Z]
     */
    function recupAlpha($chiffre)
    {
    	return chr($chiffre + ord('A'));
	}


    /**
     * Conversion lettre vers chiffre [A-Z] => [0-25]
     */
    function recupDec($chiffre)
	{
        return ord($chiffre) - ord('A');
	}


    /**
     * Conversion lettre vers chiffre [A-Z] => [0-25]
     */
    function recupAlpha1($derive, $chiffre)
	{
    	$lettre = $chiffre + $derive + ord('A');

    	// Dans le cas du cryptage, le chiffre peut exéder 25, dans ce cas on récupère la bonne lettre
    	if($lettre>90)	{$lettre = ord('A')+($lettre-91);}

    	// Dans le cas du décryptage, le chiffre peut être inférieur à 0, dans ce cas, on récupère la bonne lettre
    	if($lettre<65)	{$lettre = ord('Z')-(-$lettre+64);}
    	return chr($lettre);
	}
}

// Exemple d'utilisation
// $test = new codeUniq;
//
// // Encodage
// echo chr(10);
// $alpha = $test->encodeAlpha(1);
// echo $alpha . chr(10);
//
// // Décodage
// $recupCode = $test->decodeAlpha($alpha);
//
// // Validité du code
// if ($recupCode === false) {
//     echo 'Code invalide' . chr(10);
// } else {
//     echo $recupCode . chr(10) . chr(10);
// }
//
// // Récupération d'une liste de code dans un range définit
// $debut_plage = 0;
// $fin_plage   = 5;
//
// echo $test->listeEncodeAlpha($debut_plage, $fin_plage) .  chr(10);
