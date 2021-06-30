<?php

namespace Inbenta\GoogleConnector\ExternalAPI;

class GoogleAPIClient
{
    /**
     * Create the external id
     */
    public static function buildExternalIdFromRequest()
    {
        $request = json_decode(file_get_contents('php://input'));

        if (!$request) {
            $request = (object)$_GET;
        }
        return isset($request->session->id) ? 'google-' . str_replace("_", "-", $request->session->id) : null;
    }

    /**
     * Overwritten, not necessary with Google
     */
    public function showBotTyping($show = true)
    {
        return true;
    }

}
