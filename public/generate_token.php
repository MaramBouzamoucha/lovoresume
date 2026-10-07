<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Google\Client;
use Google\Service\Forms;

// Authentification
$keyFilePath = 'C:/Users/User/OneDrive/Bureau/LovoresumeavantSecurity/lovoresume cv/config/formulaire.json';

$client = new Client();
$client->setAuthConfig($keyFilePath);
$client->addScope('https://www.googleapis.com/auth/forms.body');

$accessToken = $client->fetchAccessTokenWithAssertion();
$client->setAccessToken($accessToken);

$formsService = new Forms($client);

// Étape 1 : Créer un formulaire avec uniquement un titre
$form = new Forms\Form();
$form->setInfo(new Forms\Info([
    'title' => 'Formulaire de test'
]));

try {
    $createdForm = $formsService->forms->create($form);
    $formId = $createdForm->getFormId();
    echo "Formulaire créé : " . $createdForm->getResponderUri() . "\n";
} catch (Exception $e) {
    echo "Erreur création formulaire : " . $e->getMessage() . "\n";
    exit;
}

// Étape 2 : Ajouter une question avec batchUpdate
$requests = [
    new Google\Service\Forms\Request([
        'createItem' => [
            'item' => [
                'title' => 'Quel est votre nom ?',
                'questionItem' => [
                    'question' => [
                        'required' => true,
                        'textQuestion' => ['paragraph' => false]
                    ]
                ]
            ],
            'location' => ['index' => 0]
        ]
    ])
];

$updateRequest = new Google\Service\Forms\BatchUpdateFormRequest([
    'requests' => $requests
]);

try {
    $formsService->forms->batchUpdate($formId, $updateRequest);
    echo "Question ajoutée au formulaire.\n";
} catch (Exception $e) {
    echo "Erreur lors de l'ajout de la question : " . $e->getMessage() . "\n";
}
