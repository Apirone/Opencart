<?php

namespace ApironeApi;

class Error
{
    public $errors = array();

    /**
     * 
     * @param string $code 
     * @param string $body 
     * @param string $data 
     * @return void 
     */
    public function __construct($code = '', $body = '', $data = '' )
    {
        if (empty($code)) {
            return;
        }

        $this->add( $code, $body, $data );
    }

    public function add( $code, $body, $data = '')
    {
        $this->errors[$code]['body'] = $body;
        $this->errors[$code]['info'] = $data;
    }

    public function hasError ()
    {
        return (!empty($this->errors)) ? true : false;
    }

    public function __toString()
    {
        return (!empty($this->errors)) ? print_r((array)$this, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_NUMERIC_CHECK) : '';
    }

}
