<?php

//-----------------------------------------------------------------------------------------------------------------------------------
//
// Filename   : Authentication_FoafSSLAbstract.php
// Date       : 14th Feb 2010
//
// See Also   : https://foaf.me/testLibAuthentication.php
//
// Copyright 2008-2010 foaf.me
//
// This program is free software: you can redistribute it and/or modify
// it under the terms of the GNU Affero General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// This program is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU Affero General Public License for more details.
//
// You should have received a copy of the GNU Affero General Public License
// along with this program. If not, see <http://www.gnu.org/licenses/>.
//
// "Everything should be made as simple as possible, but no simpler."
// -- Albert Einstein
//
//-----------------------------------------------------------------------------------------------------------------------------------

require_once("lib/Authentication_Helper.php");
require_once("lib/Authentication_Session.php");

abstract class Authentication_FoafSSLAbstract {

    private $SSLClientCert      = NULL;
    public  $certModulus        = NULL;
    public  $certExponent       = NULL;
    public  $certSubjectAltName = NULL;
    public  $webid              = NULL;
    public  $isAuthenticated    = 0;
    public  $authnDiagnostic    = NULL;

    public function __construct($createSession = TRUE, $SSLClientCert = NULL) {

        if ($createSession) {
            $session = new Authentication_Session();
            if ($session->isAuthenticated) {
                $this->webid = $session->webid;
                $this->isAuthenticated = $session->isAuthenticated;
                $this->authnDiagnostic = "Authenticated via a session";
                return;
            }
        }

        $SSLClientCert = isset($SSLClientCert)?$SSLClientCert:$_SERVER['SSL_CLIENT_CERT'];

        $this->SSLClientCert = $SSLClientCert;

        if ($this->SSLClientCert) {

            $this->opensslPkeyGetPublicHex();
            $this->opensslGetSubjectAltName();
            $this->getAuth();

        } else {

            $this->isAuthenticated = 0;
            $this->authnDiagnostic = "No Client Certificate Supplied";

        }

        if ($createSession) {
            if ($this->isAuthenticated)
                $session->setAuthenticatedWebid($this->webid);
            else
                $session->unsetAuthenticatedWebid();
        }
    }

    public function Authentication_FoafSSLAbstract($SSLClientCert = NULL) {

        $this->__construct($SSLClientCert);

    }

    public function __destruct() {

        //echo "\ndestructing " . get_class($this);

    }

    public function __init() {

    }

    /*  */

    /* Function to return the modulus and exponent of the supplied Client SSL Page */
    protected function opensslPkeyGetPublicHex() {

        if ($this->SSLClientCert) {

            $pubKey  = openssl_pkey_get_public($this->SSLClientCert);
            $keyData = openssl_pkey_get_details($pubKey);

            //Remove certificate armour
            $keyLen   = strlen($keyData['key']);
            $beginLen = strlen('-----BEGIN PUBLIC KEY----- ');
            $endLen   = strlen(' -----END PUBLIC KEY----- ');

            $RSACert = substr($keyData['key'], $beginLen, $keyLen - $beginLen - $endLen);

            //TODO: remove openssl dependency
            $RSACertStruct = `echo "$RSACert" | openssl asn1parse -inform PEM -i`;

            $RSACertFields = split("\n", $RSACertStruct);
            $RSAKeyOffset   = split(":",  $RSACertFields[4]);

            //TODO: remove openssl dependency
            $RSAKey = `echo "$RSACert" | openssl asn1parse -inform PEM -i -strparse $RSAKeyOffset[0]`;

            $RSAKeys = split("\n", $RSAKey);
            $modulus  = split(":", $RSAKeys[1]);
            $exponent = split(":", $RSAKeys[2]);

            $this->certModulus  = ltrim($modulus[3],'0');
            $this->certExponent = hexdec($exponent[3]);

        }

    }

    /* Returns an array holding the subjectAltName of the supplied SSL Client Certificate */
    protected function opensslGetSubjectAltName() {

        if ($this->SSLClientCert) {

            $cert = openssl_x509_parse($this->SSLClientCert);

            if ($cert['extensions']['subjectAltName']) {
                $list          = split("[,]", $cert['extensions']['subjectAltName']);

                for ($i = 0, $i_max = count($list); $i < $i_max; $i++) {

                    if (strcasecmp($list[$i],"")!=0) {

                        $value = split(":", $list[$i], 2);

                        if ($subjectArray)
                            $subjectArray = array_merge($subjectArray, array(trim($value[0]) => trim($value[1])));
                        else
                            $subjectArray = array(trim($value[0]) => trim($value[1]));

                    }

                }

                $this->certSubjectAltName = $subjectArray;

            }

        }

    }

    /* Function to compare the certifactes keys against the keys found in the FOAF */
    protected function equalRsaKeys($foafKeys) {

        if ( $this->certExponent && $this->certModulus && $foafKeys) {

            foreach ($foafKeys as $foafKey) {

                if ( ($this->certModulus == Authentication_Helper::cleanHex($foafKey['modulus'])) && ($this->certExponent == Authentication_Helper::cleanHex($foafKey['exponent'])) )
                    return TRUE;

            }

            return FALSE;

        }

    }

    abstract protected function getAgentRSAKey();
    // A concrete class must implement this method to return an array of arrays containing the modulus and exponent keys for the referenced $this->webid

    protected function getAuth() {

        if ( ($this->certModulus==NULL) || ($this->certExponent==NULL) ) {

            $this->isAuthenticated = 0;
            $this->authnDiagnostic = 'No RSA Key in the supplied client certificate';

        }
        else {

            $this->webid = $this->certSubjectAltName['URI'];

            $agentRSAKey = $this->getAgentRSAKey();

            if ($agentRSAKey) {

                if ($this->equalRSAKeys($agentRSAKey)) {

                    $this->isAuthenticated = 1;
                    $this->authnDiagnostic = 'Client Certificate RSAkey matches SAN RSAkey';

                } else {

                    $this->isAuthenticated = 0;
                    $this->authnDiagnostic = 'Client Certificate RSAkey does not match SAN RSAkey';

                }

            } else {

                $this->isAuthenticated = 0;
                $this->authnDiagnostic = 'No RSAKey found at supplied agent';

            }

        }

    }

}

?>
