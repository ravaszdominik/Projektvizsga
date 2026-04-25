<?php 
require_once('config.php');

$pdo = db();
function generalVeletlenFajlnev($hossz = 16): string {
    return bin2hex(random_bytes($hossz / 2));
}

$feltoltottKepek = $_FILES['kepek'] ?? null;

if (!$feltoltottKepek || empty($feltoltottKepek['name'][0])) {
    die("Nem sikerült feltölteni a képeket.");
}

$celMappa = "uploads/";
if (!is_dir($celMappa)) {
    mkdir($celMappa, 0755, true);
}
$sikeresFeltoltesek = 0;
$osszesKep = count($feltoltottKepek['name']);
foreach ($feltoltottKepek['tmp_name'] as $index => $ideiglenesUtvonal) {
if ($feltoltottKepek['error'][$index]) continue; // ha valamelyik kép hibás, lépjünk tovább, azt nem töltjük fel

    $eredetiFajlnev = basename($feltoltottKepek['name'][$index]); // eredeti fájlnév
    $ujFajlnev = generalVeletlenFajlnev() . '.webp'; // véletlen új név
    $celUtvonal = $celMappa . $ujFajlnev; // mentési útvonal

    try {
        $forras = \Tinify\fromFile($ideiglenesUtvonal); // képfájl beolvasás
        $webp = $forras->convert(["type" => "image/webp"]); // webp formátumba konvertálás
        $webp->toFile($celUtvonal); // fájl mentése

        $vegsoMeret = filesize($celUtvonal); // fájlméret lekérése

        $stmt = $pdo->prepare("INSERT INTO images (original_filename, hashed_filename, filesize) VALUES (:original_filename, :hashed_filename, :filesize)");
        $stmt->execute([
            'original_filename' => $eredetiFajlnev,
            'hashed_filename'   => $ujFajlnev,
            'filesize'          => $vegsoMeret
        ]);
        
        $sikeresFeltoltesek++;

    } catch (\Tinify\Exception $e) {
        echo "Tinify hiba: " . $e->getMessage() . " ($eredetiFajlnev)<br>";
    } catch (PDOException $e) {
        echo "Adatbázis hiba: " . $e->getMessage() . " ($eredetiFajlnev)<br>";
    }
}
echo "$osszesKep képből $sikeresFeltoltesek sikeresen feltöltve.";
