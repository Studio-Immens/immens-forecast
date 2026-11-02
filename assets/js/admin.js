( function () {
	'use strict';

	var config = window.immensForecast;
	if ( ! config ) {
		return;
	}

	var metaElement = document.getElementById( 'immens-forecast-meta' );
	var meta = { sources: {}, providers: {}, defaults: {} };
	if ( metaElement ) {
		try {
			meta = JSON.parse( metaElement.textContent );
		} catch ( error ) {
			meta = { sources: {}, providers: {}, defaults: {} };
		}
	}

	var chart = null;

	function post( action, data ) {
		var body = new URLSearchParams();
		body.append( 'action', action );
		body.append( 'nonce', config.nonce );
		Object.keys( data ).forEach( function ( key ) {
			if ( data[ key ] !== null && data[ key ] !== undefined ) {
				body.append( key, data[ key ] );
			}
		} );
		return fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} ).then( function ( response ) {
			return response.json();
		} );
	}

	function showError( container, message ) {
		if ( ! container ) {
			return;
		}
		container.querySelector( 'p' ).textContent = message || config.i18n.error;
		container.hidden = false;
	}

	function hideError( container ) {
		if ( container ) {
			container.hidden = true;
		}
	}

	function renderKpis( payload ) {
		var container = document.getElementById( 'immens-forecast-kpis' );
		if ( ! container ) {
			return;
		}
		var kpis = payload.kpis || {};
		var rows = [
			[ 'Forecast total', formatNumber( kpis.forecast_total ) ],
			[ 'Previous total', formatNumber( kpis.previous_total ) ],
			[ 'Delta', formatPercent( kpis.delta_pct ) ],
			[ 'Interval width', formatNumber( kpis.interval_width ) ]
		];
		container.innerHTML = '';
		rows.forEach( function ( row ) {
			var card = document.createElement( 'div' );
			card.className = 'immens-forecast-kpi';
			var label = document.createElement( 'span' );
			label.className = 'immens-forecast-kpi-label';
			label.textContent = row[ 0 ];
			var value = document.createElement( 'span' );
			value.className = 'immens-forecast-kpi-value';
			value.textContent = row[ 1 ];
			card.appendChild( label );
			card.appendChild( value );
			container.appendChild( card );
		} );
	}

	function formatNumber( value ) {
		if ( value === null || value === undefined || isNaN( value ) ) {
			return '--';
		}
		return Number( value ).toLocaleString();
	}

	function formatPercent( value ) {
		if ( value === null || value === undefined || isNaN( value ) ) {
			return '--';
		}
		return Number( value ).toFixed( 1 ) + '%';
	}

	function pad( length ) {
		var out = [];
		for ( var i = 0; i < length; i++ ) {
			out.push( null );
		}
		return out;
	}

	function renderChart( payload, canvas ) {
		if ( ! canvas || typeof Chart === 'undefined' ) {
			return;
		}

		var history = payload.history || { dates: [], values: [] };
		var historyLength = history.dates.length;
		var futureLength = ( payload.dates || [] ).length;
		var lower = payload.quantiles ? payload.quantiles[ String( Object.keys( payload.quantiles )[ 0 ] ) ] : null;
		var upper = payload.quantiles ? payload.quantiles[ String( Object.keys( payload.quantiles ).pop() ) ] : null;

		var datasets = [
			{
				label: 'History',
				data: history.values.concat( pad( futureLength ) ),
				borderColor: '#2271b1',
				backgroundColor: 'rgba(34, 113, 177, 0.08)',
				pointRadius: 0,
				borderWidth: 2,
				tension: 0.2
			}
		];

		if ( upper && lower ) {
			datasets.push( {
				label: 'Upper',
				data: pad( historyLength ).concat( upper ),
				borderColor: 'rgba(214, 54, 56, 0.35)',
				backgroundColor: 'rgba(214, 54, 56, 0.12)',
				pointRadius: 0,
				borderWidth: 1,
				tension: 0.2
			} );
			datasets.push( {
				label: 'Lower',
				data: pad( historyLength ).concat( lower ),
				borderColor: 'rgba(214, 54, 56, 0.35)',
				backgroundColor: 'rgba(214, 54, 56, 0.12)',
				fill: '-1',
				pointRadius: 0,
				borderWidth: 1,
				tension: 0.2
			} );
		}

		datasets.push( {
			label: 'Forecast',
			data: pad( historyLength ).concat( payload.median || [] ),
			borderColor: '#d63638',
			borderDash: [ 6, 4 ],
			pointRadius: 0,
			borderWidth: 2,
			tension: 0.2
		} );

		if ( chart ) {
			chart.destroy();
		}

		chart = new Chart( canvas.getContext( '2d' ), {
			type: 'line',
			data: {
				labels: ( history.dates || [] ).concat( payload.dates || [] ),
				datasets: datasets
			},
			options: {
				responsive: true,
				maintainAspectRatio: false,
				animation: false,
				interaction: { mode: 'index', intersect: false },
				plugins: { legend: { position: 'bottom' } },
				scales: { y: { beginAtZero: false } }
			}
		} );
	}

	function renderTable( payload ) {
		var container = document.getElementById( 'immens-forecast-table' );
		if ( ! container ) {
			return;
		}
		var quantileKeys = payload.quantiles ? Object.keys( payload.quantiles ).sort( function ( a, b ) { return a - b; } ) : [];
		var lower = quantileKeys.length ? payload.quantiles[ quantileKeys[ 0 ] ] : null;
		var upper = quantileKeys.length ? payload.quantiles[ quantileKeys[ quantileKeys.length - 1 ] ] : null;
		var rows = [ '<table class="widefat striped"><thead><tr>' ];
		rows.push( '<th>Date</th><th>Forecast</th>' );
		if ( lower ) {
			rows.push( '<th>' + quantileKeys[ 0 ] + '</th>' );
		}
		if ( upper && quantileKeys.length > 1 ) {
			rows.push( '<th>' + quantileKeys[ quantileKeys.length - 1 ] + '</th>' );
		}
		rows.push( '</tr></thead><tbody>' );
		( payload.dates || [] ).forEach( function ( date, index ) {
			rows.push( '<tr><td>' + date + '</td><td>' + formatNumber( payload.median[ index ] ) + '</td>' );
			if ( lower ) {
				rows.push( '<td>' + formatNumber( lower[ index ] ) + '</td>' );
			}
			if ( upper && quantileKeys.length > 1 ) {
				rows.push( '<td>' + formatNumber( upper[ index ] ) + '</td>' );
			}
			rows.push( '</tr>' );
		} );
		rows.push( '</tbody></table>' );
		container.innerHTML = rows.join( '' );
	}

	function refreshManualField() {
		var source = document.getElementById( 'immens-forecast-source' );
		var manual = document.getElementById( 'immens-forecast-manual-field' );
		var metric = document.getElementById( 'immens-forecast-metric' );
		if ( ! source || ! meta.sources[ source.value ] ) {
			return;
		}
		if ( manual ) {
			manual.hidden = 'manual' !== source.value;
		}
		if ( metric ) {
			metric.innerHTML = '';
			var metrics = meta.sources[ source.value ].metrics || {};
			Object.keys( metrics ).forEach( function ( id ) {
				var option = document.createElement( 'option' );
				option.value = id;
				option.textContent = metrics[ id ];
				metric.appendChild( option );
			} );
		}
	}

	var form = document.getElementById( 'immens-forecast-form' );
	if ( form ) {
		var source = document.getElementById( 'immens-forecast-source' );
		if ( source ) {
			source.addEventListener( 'change', refreshManualField );
		}
		refreshManualField();

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			var errorBox = document.getElementById( 'immens-forecast-error' );
			var fallbackBox = document.getElementById( 'immens-forecast-fallback' );
			hideError( errorBox );
			if ( fallbackBox ) {
				fallbackBox.hidden = true;
			}

			var data = {
				source: form.source.value,
				metric: form.metric.value,
				granularity: form.granularity.value,
				history_days: form.history_days.value,
				horizon: form.horizon.value,
				provider: form.provider.value,
				model: form.model.value,
				refresh: form.refresh.checked ? 1 : ''
			};
			if ( 'manual' === form.source.value ) {
				data.dataset_id = form.dataset_id.value;
			}

			post( 'immens_forecast_run', data ).then( function ( response ) {
				if ( ! response || ! response.success ) {
					showError( errorBox, response && response.data ? response.data.message : null );
					return;
				}
				var payload = response.data;
				renderKpis( payload );
				renderChart( payload, document.getElementById( 'immens-forecast-chart' ) );
				renderTable( payload );
				if ( payload.fallback && fallbackBox ) {
					fallbackBox.querySelector( 'p' ).textContent = config.i18n.fallback + ( payload.fallback_reason ? ' (' + payload.fallback_reason + ')' : '' );
					fallbackBox.hidden = false;
				}
			} ).catch( function () {
				showError( errorBox, null );
			} );
		} );
	}

	function endpointData() {
		return {
			endpoint_url: document.getElementById( 'immens-forecast-setting-url' ).value,
			endpoint_token: document.getElementById( 'immens-forecast-setting-token' ).value,
			endpoint_model: document.getElementById( 'immens-forecast-setting-model' ).value,
			endpoint_timeout: document.getElementById( 'immens-forecast-setting-timeout' ).value
		};
	}

	function bindEndpointButton( id, action, okMessage ) {
		var button = document.getElementById( id );
		var status = document.getElementById( 'immens-forecast-endpoint-status' );
		if ( ! button || ! status ) {
			return;
		}
		button.addEventListener( 'click', function () {
			var original = button.textContent;
			button.disabled = true;
			button.textContent = config.i18n.loading;
			status.className = 'immens-forecast-status';
			status.textContent = '';
			post( action, endpointData() ).then( function ( response ) {
				button.disabled = false;
				button.textContent = original;
				if ( response && response.success ) {
					status.className = 'immens-forecast-status is-success';
					status.textContent = okMessage;
				} else {
					status.className = 'immens-forecast-status is-error';
					status.textContent = response && response.data ? response.data.message : config.i18n.testFailed;
				}
			} ).catch( function () {
				button.disabled = false;
				button.textContent = original;
				status.className = 'immens-forecast-status is-error';
				status.textContent = config.i18n.testFailed;
			} );
		} );
	}

	bindEndpointButton( 'immens-forecast-test', 'immens_forecast_test_endpoint', config.i18n.testOk );
	bindEndpointButton( 'immens-forecast-models', 'immens_forecast_load_models', config.i18n.modelsLoaded );
}() );
