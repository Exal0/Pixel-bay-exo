<?php 

echo "<h1> MISSION 2 </h1>";


$jeux = [
    "Overwatch",
    "League Of Legends",
    "Dota 2",
    "destiny 2",
    "once human",
];


echo $jeux[1];
echo "<br>";
$jeux[1] = "pokemonGo";
echo $jeux[1];

echo "<br>";

$jeuStar = [
    "titre" => "overwatch",
    "prix" => 0,
    "genre" => "FPS",
    "stock" => True
];

echo "<h2> Notre jeu star est </h2>";

foreach ($jeuStar as $key => $value) {
    if ($key === "stock" && $value == True) {
        $value = "En stock";
    }
    echo "<br>";
    echo "$key : $value";
}

echo "<hr>";
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