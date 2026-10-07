<?php

require 'vendor/autoload.php';

use Smalot\PdfParser\Parser;

function convertPdfToHtml($pdfFile, $outputHtml)
{
    $parser = new Parser();
    $pdf = $parser->parseFile($pdfFile);
    $text = $pdf->getText();

    // Nettoyage du texte
    $htmlContent = "<!DOCTYPE html>
    <html lang='fr'>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>Fiche de Compétence</title>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; margin: 20px; }
            h1, h2 { color: #333; }
            .container { max-width: 800px; margin: auto; padding: 20px; border: 1px solid #ddd; border-radius: 8px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <h1>Fiche de Compétence</h1>
            <pre>$text</pre>
        </div>
    </body>
    </html>";

    // Sauvegarde en HTML
    file_put_contents($outputHtml, $htmlContent);
    echo "Conversion terminée : $outputHtml";
}

// Exemple d'utilisation
convertPdfToHtml('C:\Users\User\OneDrive\Bureau\lovoresume\public\Fiche de Compétence.pdf', 'fiche_competence.html');

?>
