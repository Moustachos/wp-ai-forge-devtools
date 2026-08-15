const NAME_WIDTH = 36;

export function report( summary, stamp, log ) {
	log( '' );
	log( `run ${ stamp } (${ ( summary.durationMs / 1000 ).toFixed( 1 ) }s)` );

	for ( const journey of summary.journeys ) {
		const label = `${ journey.id } ${ journey.name }`.padEnd( NAME_WIDTH, ' ' );
		const detail = journey.reason || journey.error || '';

		log(
			`  ${ label } ${ journey.status.toUpperCase().padEnd( 8, ' ' ) } ` +
				`${ ( journey.durationMs / 1000 ).toFixed( 1 ) }s  ${ detail }`
		);
	}

	log( '' );
	log( `artifacts: ${ summary.artifacts }` );
	log( `result: ${ summary.status.toUpperCase() }` );
}
