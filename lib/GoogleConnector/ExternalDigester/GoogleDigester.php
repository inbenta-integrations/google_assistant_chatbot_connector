<?php

namespace Inbenta\GoogleConnector\ExternalDigester;

use Inbenta\ChatbotConnector\ExternalDigester\Channels\DigesterInterface;
use Inbenta\GoogleConnector\Helpers\Helper;
use DOMDocument;
use \Exception;

class GoogleDigester extends DigesterInterface
{
    protected $conf;
    protected $channel;
    protected $langManager;
    protected $session;

    public function __construct($langManager, $conf, $session)
    {
        $this->langManager = $langManager;
        $this->channel = 'Google';
        $this->conf = $conf;
        $this->session = $session;
    }

    /**
     *	Returns the name of the channel
     */
    public function getChannel()
    {
        return $this->channel;
    }

    /**
     *	Overwritten function
     */
    public static function checkRequest($request)
    {
        return true;
    }


    /*
     * Only called when parse_date is set to 1
     * Tries to translate user input to a mm/dd/yyyy date format.
     */
    protected function parseDateString($message)
    {
        $time = strtotime($message);
        if ($time == null) {
            return $message;
        } else {
            $date = date('m/d/Y', $time);
            if ($date) {
                $this->session->delete('parse_date');
                return $date;
            }
        }
    }

    protected function parsePhoneString($message)
    {
        $mobileregex = "/^[1-9][0-9]{9}$/";
        if (!preg_match($mobileregex, $message)) {
            $message_new = str_replace("-", "", $message);
            if (preg_match($mobileregex, $message_new)) {
                return $message_new;
            }
        }
        return $message;
    }

    /**
     * Can be used to log and alter raw input from Google Action
     */
    protected function parseGoogleMessage($message)
    {
        if ($this->session->get('parse_date', 0) == 1) {
            return $this->parseDateString($message);
        }
        $message = $this->parsePhoneString($message);

        return $message;
    }

    /**
     *	Formats a channel request into an Inbenta Chatbot API request
     */
    public function digestToApi($request)
    {
        $request = json_decode($request);
        if (is_null($request)) {
            return [];
        }
        $this->session->set('conversation_id', $request->session->id);

        $response = [];
        switch ($request->intent->name) {
            case 'INPUT':
                if ($this->session->has('options')) {
                    $response = $this->checkOptions($request->intent->query);
                }
                if (count($response) == 0) {
                    $response = ['message' => $this->parseGoogleMessage($request->intent->query)];
                }
                break;
            case 'actions.intent.MAIN':
                $response = ['directCall' => 'sys-welcome'];
                break;
            case 'actions.intent.CANCEL':
                $response = ['directCall' => 'sys-goodbye'];
                break;
            default:
                # There should not be any other Intents
                throw new Exception('Invalid request! (' . $request->intent->name . ' is not accounted for)');
        }
        return $response;
    }

    /**
     * Check if the response has options
     * @param string $userMessage
     * @return array $output
     */
    protected function checkOptions(string $userMessage)
    {
        $output = [];
        $lastUserQuestion = $this->session->get('lastUserQuestion');
        $options = $this->session->get('options');
        $this->session->delete('options');
        $this->session->delete('lastUserQuestion');

        $selectedOption = false;
        $selectedOptionText = "";
        $isListValues = false;
        $isPolar = false;
        $optionSelected = false;
        foreach ($options as $option) {
            if (isset($option->list_values)) {
                $isListValues = true;
            } else if (isset($option->is_polar)) {
                $isPolar = true;
            }
            if (Helper::removeAccentsToLower($userMessage) === Helper::removeAccentsToLower($this->langManager->translate($option->label))) {
                if ($isListValues || (isset($option->attributes) && isset($option->attributes->DYNAMIC_REDIRECT) && $option->attributes->DYNAMIC_REDIRECT == 'escalationStart')) {
                    $selectedOptionText = $option->label;
                } else {
                    $selectedOption = $option;
                    $lastUserQuestion = isset($option->title) && !$isPolar ? $option->title : $lastUserQuestion;
                }
                $optionSelected = true;
                break;
            }
        }

        if (!$optionSelected) {
            if ($isListValues) { //Set again options for variable
                if ($this->session->get('optionListValues', 0) < 1) { //Make sure only enters here just once
                    $this->session->set('options', $options);
                    $this->session->set('lastUserQuestion', $lastUserQuestion);
                    $this->session->set('optionListValues', 1);
                } else {
                    $this->session->delete('options');
                    $this->session->delete('lastUserQuestion');
                    $this->session->delete('optionListValues');
                }
            } else if ($isPolar) { //For polar, on wrong answer, goes for NO
                $output['message'] = $this->langManager->translate('no');
            }
        }

        if ($selectedOption) {
            $output['option'] = $selectedOption->value;
        } else if ($selectedOptionText !== "") {
            $output['message'] = $selectedOptionText;
        }
        return $output;
    }

    /**
     *	Formats an Inbenta Chatbot API response into a channel request
     */
    public function digestFromApi($request, $lastUserQuestion = '')
    {
        $messages = [];
        //Parse request messages
        if (isset($request->answers) && is_array($request->answers)) {
            $messages = $request->answers;
        } elseif ($this->checkApiMessageType($request) !== null) {
            $messages = array('answers' => $request);
        } elseif (isset($request->messages) && count($request->messages) > 0 && $this->hasTextMessage($messages[0])) {
            // If the first message contains text although it's an unknown message type, send the text to the user
            return $this->digestFromApiAnswer($messages[0], $lastUserQuestion);
        } else {
            throw new Exception("Unknown ChatbotAPI response: " . json_encode($request, true));
        }

        $output['prompt']['firstSimple']['speech'] = "";
        foreach ($messages as $msg) {

            $msgType = $this->checkApiMessageType($msg);
            $digester = 'digestFromApi' . ucfirst($msgType);
            $digestedMessage = $this->$digester($msg, $lastUserQuestion);
            if ($digestedMessage == null) {
                continue;
            } else {
                if (!isset($output['prompt']['content']['image']) && isset($digestedMessage['prompt']['content']['image'])) {
                    $output['prompt']['content']['image'] = $digestedMessage['prompt']['content']['image'];
                }
                if (!isset($output['session']) && isset($digestedMessage['session'])) {
                    $output['session'] = $digestedMessage['session'];
                }

                $output['prompt']['firstSimple']['speech'] = $this->cleanAppend($output['prompt']['firstSimple']['speech'], $digestedMessage['prompt']['firstSimple']['speech']);
            }
        }

        return $output;
    }


    /**
     *	Classifies the API message into one of the defined $apiMessageTypes
     */
    protected function checkApiMessageType($message)
    {
        foreach ($this->apiMessageTypes as $type) {
            $checker = 'isApi' . ucfirst($type);

            if ($this->$checker($message)) {
                return $type;
            }
        }
        return null;
    }


    /********************** API MESSAGE TYPE CHECKERS **********************/

    protected function isApiAnswer($message)
    {
        return $message->type == 'answer';
    }

    protected function isApiPolarQuestion($message)
    {
        return $message->type == "polarQuestion";
    }

    protected function isApiMultipleChoiceQuestion($message)
    {
        return $message->type == "multipleChoiceQuestion";
    }

    protected function isApiExtendedContentsAnswer($message)
    {
        return $message->type == "extendedContentsAnswer";
    }

    protected function hasTextMessage($message)
    {
        return isset($message->text->body) && is_string($message->text->body);
    }




    /********************** CHATBOT API MESSAGE DIGESTERS **********************/

    protected function digestFromApiAnswer($message, $lastUserQuestion)
    {
        $image = $this->handleMessageWithImages($message->message);

        if ($image) $output['prompt']['content']['image'] = $image;

        $text = $this->cleanMessage($message->message);

        $exit = false;
        if (isset($message->attributes->DIRECT_CALL) && $message->attributes->DIRECT_CALL == "sys-goodbye") {
            $exit = true;
        }

        if (isset($message->attributes->SIDEBUBBLE_TEXT) && trim($message->attributes->SIDEBUBBLE_TEXT) !== "" && !$exit) {

            $sidebubble = $this->cleanMessage($message->attributes->SIDEBUBBLE_TEXT);
            if ($sidebubble !== '') {
                $text = $this->cleanAppend($text, $sidebubble);
            }
        }

        if (isset($message->actionField) && !empty($message->actionField) && $message->actionField->fieldType !== 'default' && !$exit) {
            $actionField = $this->handleMessageWithActionField($message, $lastUserQuestion);
            $text = $this->cleanAppend($text, $actionField);
        }

        $output['prompt']['firstSimple']['speech'] = $text;
        return $output;
    }

    protected function digestFromApiMultipleChoiceQuestion($message, $lastUserQuestion, $isPolar = false)
    {
        $text = $this->cleanMessage($message->message);

        $options = $message->options;

        foreach ($options as &$option) {

            $text = $this->cleanAppend($text, $option->label);

            if (isset($option->attributes->title) && !$isPolar) {
                $option->title = $option->attributes->title;
            } elseif ($isPolar) {
                $option->is_polar = true;
            }
        }
        $this->session->set('options', $options);
        $this->session->set('lastUserQuestion', $lastUserQuestion);

        $output['prompt']['firstSimple']['speech'] = $text;
        return $output;
    }

    protected function digestFromApiPolarQuestion($message, $lastUserQuestion)
    {
        return $this->digestFromApiMultipleChoiceQuestion($message, $lastUserQuestion, true);
    }


    protected function digestFromApiExtendedContentsAnswer($message, $lastUserQuestion)
    {
        $text = $this->cleanMessage($message->message);

        foreach ($message->subAnswers as $subAnswer) {
            $text = $this->cleanAppend($text, $subAnswer->message);
        }
        $output['prompt']['firstSimple']['speech'] = $text;
        return $output;
    }


    /********************** MISC **********************/
    public function buildEscalationMessage()
    {
        return [];
    }

    public function buildEscalatedMessage()
    {
        return [];
    }

    public function buildInformationMessage()
    {
        return [];
    }

    public function buildContentRatingsMessage($ratingOptions, $rateCode)
    {
        return [];
    }

    public function buildUrlButtonMessage($message, $urlButton)
    {
        return [];
    }

    public function handleMessageWithImages($message)
    {
        if (empty($message)) return null;
        //Capture all IMG tags and return an array with [url,alt] or null
        $elements = [];
        $doc = new DOMDocument();
        $doc->loadHTML($message);
        $tags = $doc->getElementsByTagName('img');
        if (isset($tags[0])) {
            $elements['url'] = $tags[0]->getAttribute('src');
            $elements['alt'] = $tags[0]->getAttribute('alt');
        } else {
            return null;
        }
        return $elements;
    }


    public function cleanAppend(string $s1, string $s2)
    {
        if (empty($s1)) return $this->cleanMessage($s2);
        else return $this->cleanMessage($s1) . " " . $this->cleanMessage($s2);
    }

    /**
     * Clean the message from html and other characters
     * @param string $message
     */
    public function cleanMessage(string $message)
    {
        $message = str_replace(["<br>", "</br>"], " ", $message);
        $message = strip_tags($message);
        $message = str_replace("\t", " ", $message);
        $message = str_replace("\n", " ", $message);
        $message = str_replace("&nbsp;", " ", $message);
        $message = preg_replace('/^[\pZ\pC]+|[\pZ\pC]+$/u', '', $message); //Unicode whitespace
        $message = trim($message);
        if (empty($message) || !preg_match("/[a-zA-Z0-9]+/", $message)) {
            return "";
        }
        if (!preg_match("/[?!.;,:\d]$/", $message)) {
            $message .= ".";
        }
        return $message;
    }

    /**
     * Validate if the message has action fields
     * @param object $message
     * @param string $lastUserQuestion
     * @return string $output
     */
    protected function handleMessageWithActionField($message, $lastUserQuestion)
    {
        $output = "";
        if (isset($message->actionField) && !empty($message->actionField)) {
            if ($message->actionField->fieldType === 'list') {
                $output = $this->handleMessageWithListValues($message->actionField->listValues, $lastUserQuestion);
            } elseif ($message->actionField->fieldType === 'datePicker') {
                $this->session->set('parse_date', 1);
            }
        }
        return $output;
    }

    /**
     * Set the options for message with list values
     * @param object $listValues
     * @param string $lastUserQuestion
     * @return array $output
     */
    protected function handleMessageWithListValues(object $listValues, $lastUserQuestion)
    {
        $output = "";
        $options = $listValues->values;
        foreach ($options as $index => &$option) {
            $option->list_values = true;
            $option->label = $option->option;
            $output = " " . $this->cleanMessage($option->label);
            if ($index == 5) break;
        }
        if (count($options) > 0) {
            $this->session->set('options', $options);
            $this->session->set('lastUserQuestion', $lastUserQuestion);
        }
        return $output;
    }
}
