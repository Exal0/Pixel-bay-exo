<?php
$catalogue = [
    ["titre" => "Cyber Race", "prix" => 49.99, "genre" => "Course"],
    ["titre" => "Dungeon Crawl", "prix" => 39.99, "genre" => "RPG"],
    ["titre" => "Battle Arena", "prix" => 29.99, "genre" => "Action"],
    ["titre" => "Pixel Quest", "prix" => 19.99, "genre" => "Aventure"],
    ["titre" => "Cyber Punk 2084", "prix" => 59.99, "genre" => "RPG"],
    ["titre" => "Racing Thunder", "prix" => 34.99, "genre" => "Course"]
];

$q = $_GET['q'] ?? "";

$result = [];
foreach ($catalogue as  $value) {
    if (stripos($value["titre"], $q) !== false) {
        
        $result[] = $value;
    }
}
?>

<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <title>Recherche - PixelBay</title>
    <link rel="stylesheet" href="../syle.css?<?= 'date='. Time()  ?>"  />
</head>

<body>
    <h1>Recherche PixelBay</h1>
    <div class="form_style">
        <div class="largeur">
            <form action="" method="GET">
                <input type="text" name="q" placeholder="Rechercher un jeu..."
                    value="<?= htmlspecialchars($_GET['q'] ?? '') ?>">
                <button type="submit">Rechercher</button>
            </form>
        </div>
        <div class="largeur">
            <table>
                <thead>
                    <tr>
                        <th>Titre</th>
                        <th>Prix</th>
                        <th>Genre</th>
                    </tr>
                </thead>
                <tbody>

                    <?php foreach ($result as $value) : ?>
                        <tr>
                            <td><?= $value['titre'] ?></td>
                            <td><?= $value['prix'] ?></td>
                            <td><?= $value['genre'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>

</html>