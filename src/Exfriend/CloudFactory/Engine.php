<?php

namespace Exfriend\CloudFactory;

use Symfony\Component\Stopwatch\Stopwatch;

class Engine {

    public $requests;
    public $threads = 100;
    public $queue;
    public $stopwatch;
    public $statistics;

    public $options = array();

    /**
     * @return mixed
     */
    public function getThreads()
    {
        return $this->threads;
    }

    /**
     * @param mixed $threads
     * @return $this
     */
    public function setThreads( $threads )
    {
        $this->threads = $threads;
        return $this;
    }

    public function clear()
    {
        $this->requests->clear();
        $this->options = array();
        $this->stopwatch = new Stopwatch();
        $this->statistics = new Statistics( $this->stopwatch );
    }


    protected function runSingle()
    {

        $working = true;
        do
        {
            $curr_req = $this->queue->pop();
            $this->stopwatch->start( 'cloudfactory.request.' . $curr_req->id );
            curl_exec( $curr_req->ch );

            $this->stopwatch->start( 'cloudfactory.processing' );

            $curr_req->parseResultContent();

            if ( $this->stopwatch->isStarted( 'cloudfactory.request.' . $curr_req->id ) )
            {
                $this->stopwatch->stop( 'cloudfactory.request.' . $curr_req->id );
            }

            $curr_req->tries_current++;

            $this->requests->setById( $curr_req );

            $curr_req->fire_callback( 'Load' );
            if ( $curr_req->valid )
            {
                $this->statistics->hook_request_Success( $curr_req );
                $curr_req->fire_callback( 'Success' );
                $working = false;
            }
            else
            {
                // Вызываем callback, который поменяет прокси or whatever
                $curr_req->fire_callback( 'Fail' );

                // добавляем запрос обратно в очередь
                if ( $curr_req->tries_current < $curr_req->tries_max )
                {
                    $this->stopwatch->start( 'cloudfactory.request.' . $curr_req->id );
                    $curr_req->rebuild_handle();
                    $this->queue->push( $curr_req );
                }
                else
                {
                    $this->statistics->hook_request_Fail( $curr_req );
                    $working = false;
                }
            }


            $this->stopwatch->stop( 'cloudfactory.processing' );

        } while ( $working );

        curl_close( $curr_req->ch );

        return $this;
    }


    public function run()
    {
        $this->stopwatch->start( 'cloudfactory.run' );

        $this->queue = new Queue( $this->requests->toArray() );

        $this->threads = ( sizeof( $this->requests ) < $this->threads ) ? sizeof( $this->requests ) : $this->threads;

        if ( $this->threads === 1 )
        {
            return $this->runSingle();
        }

        $master = curl_multi_init();


        for ( $i = 0; $i < $this->threads; $i++ )
        {
            $req = $this->queue->pop();
            curl_multi_add_handle( $master, $req->ch );
            $this->stopwatch->start( 'cloudfactory.request.' . $req->id );
        }

        do
        {
            while ( ( $execrun = curl_multi_exec( $master, $running ) ) == CURLM_CALL_MULTI_PERFORM ) ;
            if ( $execrun != CURLM_OK )
            {
                break;
            }

            // a request was just completed -- find out which one
            while ( $done = curl_multi_info_read( $master ) )
            {
                $this->stopwatch->start( 'cloudfactory.processing' );

                $curr_req = $this->requests->getByCh( $done[ 'handle' ] );
                $curr_req->parseResultContent();


                if ( $this->stopwatch->isStarted( 'cloudfactory.request.' . $curr_req->id ) )
                {
                    $this->stopwatch->stop( 'cloudfactory.request.' . $curr_req->id );
                }
                $curr_req->tries_current++;
                $this->requests->setById( $curr_req );

                $curr_req->fire_callback( 'Load' );
                if ( $curr_req->valid )
                {
                    $this->statistics->hook_request_Success( $curr_req );
                    $curr_req->fire_callback( 'Success' );
                }
                else
                {
                    // Вызываем callback, который поменяет прокси or whatever
                    $curr_req->fire_callback( 'Fail' );

                    // добавляем запрос обратно в очередь
                    if ( $curr_req->tries_current < $curr_req->tries_max )
                    {
                        $this->stopwatch->start( 'cloudfactory.request.' . $curr_req->id );

                        $curr_req->rebuild_handle();
                        $this->queue->push( $curr_req );
                    }
                    else
                    {

                        $this->statistics->hook_request_Fail( $curr_req );
                    }
                }
                // сохраняем все что нашаманили
                $this->requests->setById( $curr_req );


                // start a new request (it's important to do this before removing the old one)
                if ( $n = $this->queue->pop() )
                {
                    curl_multi_add_handle( $master, $n->ch );

                    if ( $this->stopwatch->isStarted( 'cloudfactory.request.' . $n->id ) )
                    {
                        $this->stopwatch->stop( 'cloudfactory.request.' . $n->id );
                    }
                }
                // remove the curl handle that just completed
                curl_multi_remove_handle( $master, $done[ 'handle' ] );

                $this->stopwatch->stop( 'cloudfactory.processing' );
            }
        } while ( $running );

        curl_multi_close( $master );

        $this->stopwatch->stop( 'cloudfactory.run' );

        return $this;
    }

    public function setOpt( $name, $value )
    {
        $this->options [ $name ] = $value;
        return $this;
    }

    protected function __construct()
    {
        $this->requests = new RequestCollection();
        $this->stopwatch = new Stopwatch();
        $this->statistics = new Statistics( $this->stopwatch );
    }

    public static function factory()
    {
        return new self();
    }

    public function addRequest( Request $request )
    {
        if ( $request->id === false )
        {
            $request->id = uniqid();
        }

        $request = $this->mergeOptionsForRequest( $request );
        $request->engine = $this;
        $this->requests->add( $request );
        $this->statistics->hook_request_add();

        return $this;
    }


    protected function mergeOptionsForRequest( Request $request )
    {
        $opts = array();

        foreach ( $this->options as $k => $v )
        {
            $opts [ $k ] = $v;
            curl_setopt( $request->ch, $k, $v );
        }
        foreach ( $request->request->options as $k => $v )
        {
            $opts [ $k ] = $v;
            curl_setopt( $request->ch, $k, $v );
        }

        $request->request->options = $opts;

        return $request;
    }

}


?>
