<?php

$nameGame = "Overwatch";
$prix = 29.99;
$prixpromo = 0;
$promotionSpeciale = 15;

function promotionEte($prix)
{
    $prixpromo = $prix - ($prix * 0.20);
    echo "Apres la promotion d'été le prix est de ";
    return (round($prixpromo, 2)) . "€";
}


function promoHiver($prix)
{
    $prixpromo = $prix - ($prix * 0.30);
    echo "Apres la promotion d'hiver le prix est de ";
    return (round($prixpromo, 2)) . "€";
}

function promoSpecial($prix, $promotionSpeciale)
{
    echo "Apres la promotion spécial le prix est de ";
    return round($prix * (1 - $promotionSpeciale / 100), 2) . "€";
}
function afficherPrix($nameGame, $prix, $prixpromo, $labelPromo)
{
    echo "$nameGame : $prix € → $prixpromo € ($labelPromo)<br>";
}

echo promotionEte($prix);
echo "<br>";
echo promoHiver($prix);
echo "<br>";
echo promoSpecial($prix, $promotionSpeciale);
echo "<br>";

afficherPrix($nameGame, $prix, promotionEte($prix), "Promo été -20%");
afficherPrix($nameGame, $prix, promoHiver($prix), "Promo hiver -30%");
afficherPrix($nameGame, $prix, promoSpecial($prix, 15), "Promo spéciale -15%");
?>


<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document</title>
</head>

<body>

</body>

</html>