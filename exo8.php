<?php
$commande = [
    [
        "nom" => "Cyber Race",
        "prix_unitaire" => 49.99,
        "quantite" => 2
    ],

    [
        "nom" => "Manette Pro",
        "prix_unitaire" => 59.99,
        "quantite" => 1
    ],

    [
        "nom" => "Carte Mémoire 128Go",
        "prix_unitaire" => 24.99,
        "quantite" => 3
    ]
];
$tva = 20;


function calculerTTC($prixHT, $tva)
{
    return $prixHT * (1 + $tva / 100);
}


$totalHT = 0;
foreach ($commande as $article) {
     $totalHT += $article['prix_unitaire'] * $article['quantite'];
   // $totalHT = $totalHT + $article['prix_unitaire'] * $article['quantite'];  lignes equivalente a celle du dessus
    
}

$addTva = round($totalHT *  $tva /100, 2);
$montantTTC = calculerTTC($totalHT, $tva);


?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document</title>
     <link rel="stylesheet" href="../syle.css" />
</head>

<body>
    <main>
        <table>
            <thead>
                <tr>
                    <th scopre = "col" >Article</th>
                    <th scopre = "col" >Prix unitaire</th>
                    <th scopre = "col" >Quantité</th>
                    <th scopre = "col" >Sous Total</th>
                </tr>
            </thead>
    <?php foreach ($commande as $article): ?>
 <td><?=$article["nom"] ?></td>
 <td><?=$article["prix_unitaire"] ?></td>
 <td><?=$article["quantite"] ?></td>
 <td><?= round($article['prix_unitaire']* $article['quantite'], 2)?> €</td>

</tr>
 <?php endforeach; ?>
 <tr class="total">
    <td colspan="3">TOTAL HT</td>
    <td><?= round($totalHT, 2) ?> €</td>
 </tr>
 <tr class="total">
<td colspan="3">Tva 20%</td>
<td><?= $addTva ?>€</td>
 </tr>

 <tr>
    <td colspan="3">Montant TTC</td>
    <td><?= $montantTTC ?> €</td>
 </tr>

    </main>
</body>

</html>