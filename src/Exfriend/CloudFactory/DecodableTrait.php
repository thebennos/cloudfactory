<?php


namespace Exfriend\CloudFactory;

trait DecodableTrait
{

    /**
     * @return $this
     */
    public function decode()
    {
        if ( isset( $this->remoteEncoding ) )
        {
            $this->result = mb_convert_encoding( $this->result, 'UTF-8', $this->remoteEncoding );
        }
        return $this;
    }

}