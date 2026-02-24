<?php

$collection = [
    [
        "titre" => "Overwatch",
        "prix" => 29.99,
        "genre" => "FPS",
        "stock" => 20,
        "scores" => [75, 90, 78, 92, 88]
    ],
    [
        "titre" => "League Of Legends",
        "prix" => 288.44,
        "genre" => "MOBA",
        "stock" => 30,
        "scores" => [25, 10, 98, 99, 98]
    ],
    [
        "titre" => "Destiny 2",
        "prix" => 528.48,
        "genre" => "MmoRpg",
        "stock" => 40,
        "scores" => [75, 70, 78, 72, 78]
    ],
    [
        "titre" => "Once Human",
        "prix" => 10.99,
        "genre" => "MMO Survival",
        "stock" => 50,
        "scores" => [65, 60, 86, 26, 68]
    ]
];

function afficherJeu($jeu) {
    echo "titre : " . $jeu['titre'] . "<br>";
    echo "prix : " . $jeu['prix'] . " €<br>";
    echo "genre : " . $jeu['genre'] . "<br>";
    echo "stock : " . $jeu['stock'] . "<br>";
    echo "scores : " . implode(" / ", $jeu['scores']) . "<br>";
}

function calculerMoyenne($jeu) {
    $somme = array_sum($jeu['scores']);
    $nb = count($jeu['scores']);
    return $somme / $nb;
}

function bestGame($collection) {

    $meilleurJeu = null;
    $meilleureMoyenne = 0;

    foreach ($collection as $jeu) {

        $moyenne = calculerMoyenne($jeu);

        if ($moyenne > $meilleureMoyenne) {
            $meilleureMoyenne = $moyenne;
            $meilleurJeu = $jeu;
        }
    }

    return $meilleurJeu;
}


afficherJeu($collection[1]);
echo "<br>Moyenne : " . calculerMoyenne($collection[1]);

echo "<hr>";

$meilleurJeu = bestGame($collection);
echo "<h3>Meilleur jeu</h3>";
afficherJeu($meilleurJeu);
echo "<br>Moyenne : " . calculerMoyenne($meilleurJeu);

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