<?php

namespace Inbenta\GoogleConnector;

use \Exception;
use Inbenta\ChatbotConnector\ChatbotConnector;
use Inbenta\ChatbotConnector\ChatbotAPI\ChatbotAPIClient;
use Inbenta\ChatbotConnector\Utils\SessionManager;
use Inbenta\GoogleConnector\ExternalAPI\GoogleAPIClient;
use Inbenta\GoogleConnector\ExternalDigester\GoogleDigester;
use \Firebase\JWT\JWT;

class GoogleConnector extends ChatbotConnector
{
    private $messages;

    public function __construct($appPath)
    {
        // Initialize and configure specific components for Google
        try {
            parent::__construct($appPath);

            // Initialize base components
            $request = file_get_contents('php://input');

            //Validity check
            $this->validityCheck();

            $externalId = $this->getExternalIdFromRequest();

            $conversationConf = array(
                'configuration' => $this->conf->get('conversation.default'),
                'userType'      => $this->conf->get('conversation.user_type'),
                'environment'   => $this->environment,
                'source'        => $this->conf->get('conversation.source')
            );

            $this->session = new SessionManager($externalId);

            $this->botClient = new ChatbotAPIClient(
                $this->conf->get('api.key'),
                $this->conf->get('api.secret'),
                $this->session,
                $conversationConf
            );

            $externalClient = new GoogleAPIClient(
                $this->conf->get('google.id'),
                $request
            ); // Instance Google client

            // Instance Google digester
            $externalDigester = new GoogleDigester(
                $this->lang,
                $this->conf->get('conversation.digester'),
                $this->session
            );
            $this->initComponents($externalClient, null, $externalDigester);
        } catch (Exception $e) {
            echo json_encode(["error" => $e->getMessage()]);
            die();
        }
    }

    /**
     * Get the external id from request
     *
     * @return string 
     */
    protected function getExternalIdFromRequest()
    {
        // Try to get user_id from a Google message request
        $externalId = GoogleAPIClient::buildExternalIdFromRequest();
        if (is_null($externalId)) {
            session_write_close();
            throw new Exception('Invalid request!');
        }
        return $externalId;
    }

    /**
     * Check if is a valid request from google
     */
    protected function validityCheck()
    {
        $headers = getallheaders();
        if (!isset($headers['Google-Assistant-Signature'])) {
            throw new Exception('Invalid request, no Google signature');
        }

        $jwt = $headers['Google-Assistant-Signature'];
        $elements = explode('.', $jwt);
        $bodyb64 = isset($elements[1]) ? $elements[1] : '';
        $invalid = true;
        if ($bodyb64 !== '') {
            $payload = JWT::jsonDecode(JWT::urlsafeB64Decode($bodyb64));
            $aud = $this->conf->get('google.id');
            if (isset($payload->aud) && $payload->aud == $aud) {
                $invalid = false;
            }
        }
        if ($invalid) {
            throw new Exception('Invalid request, Google authentication does not match project id.');
        }
    }

    public function handleRequest()
    {
        try {

            $request = file_get_contents('php://input');

            // Translate the request into a ChatbotAPI request
            $externalRequest = $this->digester->digestToApi($request);

            if (!$externalRequest) return;

            // Check if it's needed to perform any action other than a standard user-bot interaction
            $nonBotResponse = $this->handleNonBotActions([$externalRequest]);
            if (!is_null($nonBotResponse)) {
                return $nonBotResponse;
            }

            // Handle standard bot actions
            $this->handleBotActions([$externalRequest]);
            // Send all messages
            return $this->sendMessages();
        } catch (Exception $e) {
            return ["error" => $e->getMessage()];
        }
    }

    protected function handleNonBotActions($digestedRequest)
    {
        // If user answered to an ask-to-escalate question, handle it
        if ($this->session->get('askingForEscalation', false)) {
            return $this->handleEscalation($digestedRequest);
        }
        return null;
    }

    /**
     * Print the message that Google can process
     */
    public function sendMessages()
    {
        if (!isset($this->messages['prompt']['firstSimple']['speech']) || empty(trim($this->messages['prompt']['firstSimple']['speech']))) {
            $this->messages['prompt']['firstSimple']['speech'] = $this->lang->translate('empty_response');
        }
        return $this->messages;
    }

    protected function sendMessagesToExternal($botResponse)
    {
        // Digest the bot response into the external service format
        $this->messages = $this->digester->digestFromApi($botResponse, $this->session->get('lastUserQuestion'));
    }

    /**
     * Overwritten
     */
    protected function handleEscalation($userAnswer = null)
    {
        $this->messages['prompt']['firstSimple']['speech'] .= " " . $this->lang->translate('no_escalation');
        return $this->sendMessages();
    }
}
