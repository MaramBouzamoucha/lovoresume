<?php
namespace App\Service;
use Google\Client;
use Google\Service\Forms;
use Google\Service\Forms\Form;
use Google\Service\Forms\Request as FormsRequest;
use Google\Service\Forms\BatchUpdateFormRequest;
class GoogleFormsService
{
    private $client;
    private $formsService;
    public function __construct(string $tokenPath)
    {
        $client = new Client();
        $client->setAuthConfig($tokenPath);
        $client->addScope('https://www.googleapis.com/auth/forms.body');
        $client->addScope('https://www.googleapis.com/auth/drive');
        $client->addScope('https://www.googleapis.com/auth/spreadsheets');

        $accessToken = $client->fetchAccessTokenWithAssertion();
        $client->setAccessToken($accessToken);
        $this->client = $client;
        $this->formsService = new Forms($client);
    }
    public function getFormQuestions(string $formId): array
    {
        $form = $this->formsService->forms->get($formId);
        $items = $form->getItems();

        $questions = [];
        foreach ($items as $item) {
            if ($item->getQuestionItem()) {
                $questions[] = $item->getTitle();
            }
        }

        return $questions;
    }


    public function createFormWithQuestions(string $title, array $questions): string
    {
        $form = new Form();
        $form->setInfo(new \Google\Service\Forms\Info(['title' => $title]));
        $createdForm = $this->formsService->forms->create($form);
        $formId = $createdForm->getFormId();
        sleep(2);
        $requests = [];
        $index = 0;
        foreach ($questions as $questionText) {
            $requests[] = new FormsRequest([
                'createItem' => [
                    'item' => [
                        'title' => $questionText,
                        'questionItem' => [
                            'question' => [
                                'required' => true,
                                'textQuestion' => ['paragraph' => false]
                            ]
                        ]
                    ],
                    'location' => ['index' => $index++]
                ]
            ]);
        }
        $batchUpdateRequest = new BatchUpdateFormRequest([
            'requests' => $requests
        ]);

        $this->formsService->forms->batchUpdate($formId, $batchUpdateRequest);
        foreach ($questions as $q) {
            echo $q . "<br>";
        }
        return $createdForm->getResponderUri();
    }}