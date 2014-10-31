# Usage

```

$Metrics = Metrics::instance();

$cf = Engine::factory()
    ->addMetrics( $Metrics )
    ->setOpt( CURLOPT_HEADER, 0 )
    ->setThreads( 100 );


for ( $i = 0; $i < 3000; $i++ )
{
    $request = Request::factory( 'https://github.com/?i=' . $i )
        ->setStorage( 'i', $i )
        ->setId( $i )
        ->setOpt( CURLOPT_HEADER, true )
        ->withSsl()
        ->withCookies( 'ololo.txt' )
        ->withConnectTimeout( 4 )
        ->withLoadTimeout( 5 )
        // ->post( $psto )
        ->maxTries( 10 )
        ->onLoad( function ( Request $request )
        {
            /* валидируем результат*/
            $request->valid = ( strpos( $request->result, 'collaboration' ) !== false );
        } )
        ->onFail( function ( Request $request )
        {
            /* на репарсинг! */
            $request->valid = 0;
        } )
        ->onSuccess( 'onSuccess' );

    $cf->addRequest( $request );
}

$cf->run();

```
