<?php
namespace App\Service;
use Smalot\PdfParser\Parser;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class OpenAIChatService
{
    private const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta/models/';

    public function __construct(
        #[Autowire('%env(GEMINI_API_KEY)%')] private string $apiKey,
        #[Autowire('%env(GEMINI_MODEL)%')] private string   $model,
        private LoggerInterface                             $logger,
        private HttpClientInterface                         $httpClient
    )
    {
    }

    public function generateCvFromTemplate(string $textExtrait, string $htmlContent): string
    {
        $this->logger->info('Début génération CV avec Gemini AI');
        try {
            $prompt = $this->buildPrompt($textExtrait, $htmlContent);
            return $this->generateTextWithGemini($prompt);
        } catch (\Exception $e) {
            $this->logger->error('Erreur Gemini AI', ['error' => $e->getMessage()]);
            throw new \RuntimeException('Erreur lors de la génération du CV: ' . $e->getMessage());
        }
    }

    private function buildPrompt(string $text, string $template): string
    {
        return "Générez un CV en suivant strictement ce template HTML :\n$template\n\n"
            . "Consignes clés :\n"
            . "1. Pour les projets :\n"
            . "   - Intégrer domaine ET technologies DIRECTEMENT dans chaque bloc projet\n"
            . "   - Format :\n"
            . "     <div class='project'>\n"
            . "       <h3>NomProjet</h3>\n"
            . "       <p><strong>Domaine:</strong> [d aprés la description de projet]</p>\n"
            . "       <p><strong>Technologies:</strong> [d apres la description de projet]</p>\n"
            . "       <ul>[...]</ul>\n"
            . "     </div>\n"
            . "2. Sections globales 'DOMAINE'/'TECH' :\n"
            . "   - Laisser VIDE (supprimer tout contenu entre balises)\n"
            . "    -enlever le nom et laisser que le prénom "
            . "    -"
            . "3. Texte source à convertir :\n$text";
    }

    private function generateTextWithGemini(string $prompt): string
    {
        $url = self::BASE_URL . $this->model . ':generateContent?key=' . $this->apiKey;
        $response = $this->httpClient->request('POST', $url, [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'contents' => [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ],
                'generationConfig' => [
                    'temperature' => 0.3,
                    'topP' => 0.95,
                    'topK' => 40,
                    'maxOutputTokens' => 2048,
                    'stopSequences' => []
                ],
                'safetySettings' => [
                    [
                        'category' => 'HARM_CATEGORY_HARASSMENT',
                        'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'
                    ],
                    [
                        'category' => 'HARM_CATEGORY_HATE_SPEECH',
                        'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'
                    ],
                    [
                        'category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT',
                        'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'
                    ],
                    [
                        'category' => 'HARM_CATEGORY_DANGEROUS_CONTENT',
                        'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'
                    ]
                ]
            ]
        ]);
        $data = $response->toArray();
        if (!isset($data['candidates'][0]['content']['parts'][0]['text'])) {
            $this->logger->error('Invalid Gemini response', ['response' => $data]);
            throw new \RuntimeException('Invalid response format from Gemini API');
        }
        return $data['candidates'][0]['content']['parts'][0]['text'];
    }

    public function generateSkillSheetFromTemplate(string $textExtrait, string $htmlContent): string
    {
        $this->logger->info('Début génération fiche de compétences avec Gemini AI');
        try {
            $prompt = $this->buildSkillsPrompt($textExtrait, $htmlContent);
            return $this->generateTextWithGemini($prompt);
        } catch (\Exception $e) {
            $this->logger->error('Erreur Gemini AI (skills)', ['error' => $e->getMessage()]);
            throw new \RuntimeException('Erreur lors de la génération de la fiche de compétences: ' . $e->getMessage());
        }
    }

    private function buildSkillsPrompt(string $text, string $template): string
    {
        return "Générez une fiche de compétences HTML structurée, en suivant STRICTEMENT ce modèle :\n$template\n\n"
            . "⚠️ Règles à respecter impérativement :\n"
            . "1. Respecter la structure et les balises EXACTEMENT comme dans le template fourni.\n"
            . "2. Aucune section ne doit être vide : si une information est absente, ajouter un contenu générique approprié d aprés la description dans le texte de cv.\n"
            . "3. Les compétences doivent être regroupées en catégories :\n"
            . "   - Compétences Techniques (Langages, Frameworks, Outils, etc.)\n"
            . "   - Compétences Fonctionnelles (gestion, analyse, etc.)\n"
            . "   - Soft Skills (communication, autonomie, etc.)\n"
            . "4. Ne générer que du code HTML, sans aucun texte hors des balises.\n"
            . "5. Utiliser uniquement les informations contenues dans le texte suivant :\n$text\n"
            . "6. Ne pas affiché ```html au debut et ''' a  la fin.\n"
            . "7. Afficher uniquement le prénom du candidat (PAS le nom de famille).\n";
    }

    public function generateQuestions(string $textExtrait, string $descriptionPoste, string $tempsEntretien): string
    {
        $this->logger->info('Début génération des questions d\'entretien avec Gemini AI');

        try {
            $prompt = $this->buildQuestionsPrompt($textExtrait, $descriptionPoste, $tempsEntretien);
            return $this->generateTextWithGemini($prompt);
        } catch (\Exception $e) {
            $this->logger->error('Erreur lors de la génération des questions: ' . $e->getMessage());
            throw new \RuntimeException('Échec de la génération des questions. Veuillez réessayer.');
        }
        $this->logger->info('Prompt envoyé à Gemini', ['prompt' => $prompt]);
        $response = $this->generateTextWithGemini($prompt);
        $this->logger->info('Réponse de Gemini', ['response' => $response]);
        return $response;
    }

    private function buildQuestionsPrompt(string $textExtrait, string $descriptionPoste, string $tempsEntretien): string
    {
        return "Génère des questions d'entretien technique basées sur les informations suivantes:\n\n"
            . "1. Durée de l'entretien: $tempsEntretien minutes\n"
            . "2. Description du poste:\n$descriptionPoste\n\n"
            . "3. Compétences du candidat:\n$textExtrait\n\n"
            . "Instructions spécifiques:\n"
            . "- Générer entre 5 et 15 questions en fonction de la durée\n"
            . "- Mélanger questions techniques et questions comportementales\n"
            . "- Adapter les questions aux compétences spécifiques du candidat\n"
            . "- Formuler des questions ouvertes\n"
            . "- Organiser les questions par thématique\n"
            . "- Ne pas inclure de réponses\n";
    }

    public function extractTextFromPdf(string $filePath): string
    {
        try {
            if (!file_exists($filePath)) {
                throw new \RuntimeException("Le fichier PDF n'existe pas");
            }
            $parser = new Parser();
            $pdf = $parser->parseFile($filePath);
            $text = $pdf->getText();
            $text = trim($text);
            if (empty($text)) {
                throw new \RuntimeException("Le PDF est vide ou illisible");
            }
            $text = preg_replace('/[^\x20-\x7E\x0A\x0D]/', '', $text);
            $text = str_replace(["\r\n", "\r"], "\n", $text);
            return $text;
        } catch (\Exception $e) {
            throw new \RuntimeException("Erreur d'extraction PDF: " . $e->getMessage());
        }
    }

    private function buildEvaluationPrompt(array $inputData): string
    {
        $EvaluationText = $inputData['pdfText'];

        return <<<PROMPT
Tu es un expert en recrutement. Tu vas recevoir le contenu d'un formulaire rempli par le candidat. À partir de ce contenu, rédige une évaluation complète en langage naturel. 

Inclue les éléments suivants dans des paragraphes clairs :
- Points forts
- Points faibles
- Adéquation avec le poste
- Recommandation
- Note sur 20

Voici le contenu du formulaire :

$EvaluationText

Merci de rédiger une réponse professionnelle, fluide, sans utiliser de format JSON sans conseils car je vais l envoyer au candidat 
PROMPT;
    }

    public function generateEvaluation(array $inputData): string
    {
        $prompt = $this->buildEvaluationPrompt($inputData);
        return $this->generateTextWithGemini($prompt);
    }
    public function extractEmailFromCv(string $cvText): string
    {
        $this->logger->info('Début extraction de l\'email avec Gemini AI');

        $prompt = <<<PROMPT
Tu es un assistant intelligent. À partir du texte ci-dessous extrait d’un CV, trouve et retourne uniquement l'adresse email du candidat. Ne retourne rien d’autre, aucun commentaire, aucun mot supplémentaire.

Texte du CV :
$cvText

Donne uniquement l'adresse email, sans guillemets, sans texte autour.
PROMPT;

        try {
            $response = $this->generateTextWithGemini($prompt);
            // Nettoyage basique si Gemini renvoie par exemple "email@example.com\n"
            return trim($response);
        } catch (\Exception $e) {
            $this->logger->error('Erreur lors de l\'extraction de l\'email', ['error' => $e->getMessage()]);
            throw new \RuntimeException('Erreur lors de l\'extraction de l\'email: ' . $e->getMessage());
        }
    }



}
