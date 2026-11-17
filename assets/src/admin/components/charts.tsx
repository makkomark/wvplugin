/* Hand-rolled SVG charts: sparkline, bars (single/grouped), area line, donut, horizontal bars. */
import { useEffect, useId, useLayoutEffect, useRef, useState } from 'react';
import type { ReactNode, RefObject } from 'react';

/** Categorical order (validated: adjacent CVD ΔE ≥ 9, normal-vision ≥ 19.6). Labels always accompany colour. */
export const SERIES = [ '#5b3df5', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#2a78d6' ];

function useWidth< T extends HTMLElement >(): [ RefObject< T >, number ] {
	const ref = useRef< T >( null );
	const [ w, setW ] = useState( 0 );
	useLayoutEffect( () => {
		const el = ref.current;
		if ( ! el ) {
			return;
		}
		setW( el.clientWidth );
		const ro = new ResizeObserver( ( entries ) => setW( Math.floor( entries[ 0 ].contentRect.width ) ) );
		ro.observe( el );
		return () => ro.disconnect();
	}, [] );
	return [ ref, w ];
}

export function compact( n: number ): string {
	try {
		return new Intl.NumberFormat( undefined, { notation: 'compact', maximumFractionDigits: 1 } ).format( n );
	} catch ( e ) {
		return String( n );
	}
}

function niceScale( max: number, ticks = 4 ): { top: number; step: number } {
	if ( max <= 0 ) {
		return { top: ticks, step: 1 };
	}
	const raw = max / ticks;
	const mag = Math.pow( 10, Math.floor( Math.log10( raw ) ) );
	const norm = raw / mag;
	const nice = norm <= 1 ? 1 : norm <= 2 ? 2 : norm <= 2.5 ? 2.5 : norm <= 5 ? 5 : 10;
	const step = nice * mag;
	return { top: Math.ceil( max / step ) * step, step };
}

export function monthLabel( ym: string ): string {
	const [ y, m ] = ym.split( '-' ).map( Number );
	if ( ! y || ! m ) {
		return ym;
	}
	return new Date( y, m - 1, 1 ).toLocaleDateString( undefined, { month: 'short' } );
}

/* ---------- tooltip ---------- */

interface Tip { x: number; y: number; content: ReactNode }

function Tooltip( { tip }: { tip: Tip | null } ) {
	if ( ! tip ) {
		return null;
	}
	return (
		<div className="wvm-a-ctip" style={ { left: tip.x, top: tip.y } }>
			{ tip.content }
		</div>
	);
}

/* ---------- sparkline ---------- */

export function Sparkline( { values, color = SERIES[ 0 ], height = 32 }: { values: number[]; color?: string; height?: number } ) {
	const id = useId().replace( /:/g, '' );
	const [ ref, w ] = useWidth< HTMLDivElement >();
	if ( values.length < 2 ) {
		return <div ref={ ref } className="wvm-a-spark" style={ { height } } />;
	}
	const max = Math.max( ...values, 1 );
	const min = Math.min( ...values, 0 );
	const width = w || 120;
	const pts = values.map( ( v, i ) => [ ( i / ( values.length - 1 ) ) * ( width - 4 ) + 2, height - 3 - ( ( v - min ) / ( max - min || 1 ) ) * ( height - 8 ) ] );
	const line = pts.map( ( p, i ) => ( i ? 'L' : 'M' ) + p[ 0 ].toFixed( 1 ) + ' ' + p[ 1 ].toFixed( 1 ) ).join( ' ' );
	const last = pts[ pts.length - 1 ];
	return (
		<div ref={ ref } className="wvm-a-spark" style={ { height } }>
			{ w > 0 && (
				<svg width={ width } height={ height } aria-hidden="true">
					<defs>
						<linearGradient id={ 'sg' + id } x1="0" x2="0" y1="0" y2="1">
							<stop offset="0" stopColor={ color } stopOpacity="0.22" />
							<stop offset="1" stopColor={ color } stopOpacity="0" />
						</linearGradient>
					</defs>
					<path d={ `${ line } L${ last[ 0 ] } ${ height } L2 ${ height } Z` } fill={ `url(#sg${ id })` } />
					<path d={ line } fill="none" stroke={ color } strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
					<circle cx={ last[ 0 ] } cy={ last[ 1 ] } r="3" fill={ color } stroke="#fff" strokeWidth="2" />
				</svg>
			) }
		</div>
	);
}

/* ---------- vertical bars (one or more series) ---------- */

export interface BarDatum { label: string; values: number[]; displays?: string[] }

export function BarChart( { data, series, height = 220, format = compact }: { data: BarDatum[]; series: { name: string; color?: string }[]; height?: number; format?: ( n: number ) => string } ) {
	const [ ref, w ] = useWidth< HTMLDivElement >();
	const [ tip, setTip ] = useState< Tip | null >( null );
	const left = 44;
	const bottom = 26;
	const top = 10;
	const width = Math.max( w, 200 );
	const plotW = width - left - 8;
	const plotH = height - bottom - top;
	const max = Math.max( 0, ...data.flatMap( ( d ) => d.values ) );
	const { top: yTop, step } = niceScale( max );
	const ticks: number[] = [];
	for ( let v = 0; v <= yTop + 1e-9; v += step ) {
		ticks.push( v );
	}
	const band = plotW / Math.max( 1, data.length );
	const groupW = Math.min( band * 0.62, 26 * series.length + 4 * ( series.length - 1 ) );
	const barW = ( groupW - 2 * ( series.length - 1 ) ) / series.length;
	const y = ( v: number ) => top + plotH - ( v / yTop ) * plotH;
	return (
		<div className="wvm-a-chart" ref={ ref } onMouseLeave={ () => setTip( null ) }>
			{ w > 0 && (
				<svg width={ width } height={ height } role="img">
					{ ticks.map( ( t ) => (
						<g key={ t }>
							<line x1={ left } x2={ width - 8 } y1={ y( t ) } y2={ y( t ) } className="wvm-a-chart__grid" />
							<text x={ left - 8 } y={ y( t ) + 4 } textAnchor="end" className="wvm-a-chart__axis">
								{ format( t ) }
							</text>
						</g>
					) ) }
					{ data.map( ( d, i ) => {
						const gx = left + band * i + ( band - groupW ) / 2;
						return (
							<g key={ d.label + i }>
								<rect
									x={ left + band * i }
									y={ top }
									width={ band }
									height={ plotH }
									fill="transparent"
									className="wvm-a-chart__hit"
									onMouseMove={ ( e ) => {
										const box = ( e.currentTarget.ownerSVGElement as SVGSVGElement ).getBoundingClientRect();
										setTip( {
											x: Math.max( 80, Math.min( e.clientX - box.left, width - 80 ) ),
											y: Math.max( 56, y( Math.max( ...d.values ) ) - 10 ),
											content: (
												<>
													<strong>{ d.label }</strong>
													{ series.map( ( s, j ) => (
														<span key={ s.name } className="wvm-a-ctip__row">
															<i style={ { background: s.color || SERIES[ j ] } } />
															{ s.name }
															<b>{ d.displays?.[ j ] ?? format( d.values[ j ] ) }</b>
														</span>
													) ) }
												</>
											),
										} );
									} }
								/>
								{ d.values.map( ( v, j ) => {
									const h = Math.max( v > 0 ? 3 : 0, ( v / yTop ) * plotH );
									const x = gx + j * ( barW + 2 );
									const r = Math.min( 4, barW / 2, h );
									const yy = top + plotH - h;
									return h > 0 ? (
										<path key={ j } d={ `M${ x } ${ top + plotH } V${ yy + r } Q${ x } ${ yy } ${ x + r } ${ yy } H${ x + barW - r } Q${ x + barW } ${ yy } ${ x + barW } ${ yy + r } V${ top + plotH } Z` } fill={ series[ j ]?.color || SERIES[ j ] } className="wvm-a-chart__bar" />
									) : null;
								} ) }
								<text x={ left + band * i + band / 2 } y={ height - 8 } textAnchor="middle" className="wvm-a-chart__axis">
									{ d.label }
								</text>
							</g>
						);
					} ) }
					<line x1={ left } x2={ width - 8 } y1={ top + plotH } y2={ top + plotH } className="wvm-a-chart__base" />
				</svg>
			) }
			<Tooltip tip={ tip } />
			{ series.length > 1 && <Legend items={ series.map( ( s, i ) => ( { label: s.name, color: s.color || SERIES[ i ] } ) ) } /> }
		</div>
	);
}

/* ---------- area / line ---------- */

export function AreaChart( { data, height = 220, color = SERIES[ 0 ], format = compact, name = 'Value' }: { data: { label: string; value: number; display?: string }[]; height?: number; color?: string; format?: ( n: number ) => string; name?: string } ) {
	const id = useId().replace( /:/g, '' );
	const [ ref, w ] = useWidth< HTMLDivElement >();
	const [ hover, setHover ] = useState< number | null >( null );
	const left = 44;
	const bottom = 26;
	const top = 12;
	const width = Math.max( w, 200 );
	const plotW = width - left - 16;
	const plotH = height - bottom - top;
	const max = Math.max( 0, ...data.map( ( d ) => d.value ) );
	const { top: yTop, step } = niceScale( max );
	const ticks: number[] = [];
	for ( let v = 0; v <= yTop + 1e-9; v += step ) {
		ticks.push( v );
	}
	const x = ( i: number ) => left + 8 + ( data.length <= 1 ? plotW / 2 : ( i / ( data.length - 1 ) ) * ( plotW - 8 ) );
	const y = ( v: number ) => top + plotH - ( v / yTop ) * plotH;
	const pts = data.map( ( d, i ) => [ x( i ), y( d.value ) ] );
	// Smooth with a light cardinal spline.
	const path = pts
		.map( ( p, i ) => {
			if ( i === 0 ) {
				return `M${ p[ 0 ] } ${ p[ 1 ] }`;
			}
			const p0 = pts[ i - 1 ];
			const cx1 = p0[ 0 ] + ( p[ 0 ] - p0[ 0 ] ) / 2;
			return `C${ cx1 } ${ p0[ 1 ] } ${ cx1 } ${ p[ 1 ] } ${ p[ 0 ] } ${ p[ 1 ] }`;
		} )
		.join( ' ' );
	return (
		<div
			className="wvm-a-chart"
			ref={ ref }
			onMouseLeave={ () => setHover( null ) }
			onMouseMove={ ( e ) => {
				if ( ! data.length ) {
					return;
				}
				const box = ( e.currentTarget as HTMLDivElement ).getBoundingClientRect();
				const mx = e.clientX - box.left;
				let best = 0;
				pts.forEach( ( p, i ) => {
					if ( Math.abs( p[ 0 ] - mx ) < Math.abs( pts[ best ][ 0 ] - mx ) ) {
						best = i;
					}
				} );
				setHover( best );
			} }
		>
			{ w > 0 && (
				<svg width={ width } height={ height } role="img">
					<defs>
						<linearGradient id={ 'ag' + id } x1="0" x2="0" y1="0" y2="1">
							<stop offset="0" stopColor={ color } stopOpacity="0.24" />
							<stop offset="1" stopColor={ color } stopOpacity="0.01" />
						</linearGradient>
					</defs>
					{ ticks.map( ( t ) => (
						<g key={ t }>
							<line x1={ left } x2={ width - 8 } y1={ y( t ) } y2={ y( t ) } className="wvm-a-chart__grid" />
							<text x={ left - 8 } y={ y( t ) + 4 } textAnchor="end" className="wvm-a-chart__axis">
								{ format( t ) }
							</text>
						</g>
					) ) }
					{ pts.length > 0 && <path d={ `${ path } L${ pts[ pts.length - 1 ][ 0 ] } ${ top + plotH } L${ pts[ 0 ][ 0 ] } ${ top + plotH } Z` } fill={ `url(#ag${ id })` } /> }
					{ pts.length > 0 && <path d={ path } fill="none" stroke={ color } strokeWidth="2" strokeLinecap="round" /> }
					{ data.map( ( d, i ) => (
						<text key={ i } x={ x( i ) } y={ height - 8 } textAnchor="middle" className="wvm-a-chart__axis">
							{ d.label }
						</text>
					) ) }
					{ hover !== null && pts[ hover ] && (
						<g>
							<line x1={ pts[ hover ][ 0 ] } x2={ pts[ hover ][ 0 ] } y1={ top } y2={ top + plotH } className="wvm-a-chart__cross" />
							<circle cx={ pts[ hover ][ 0 ] } cy={ pts[ hover ][ 1 ] } r="5" fill={ color } stroke="#fff" strokeWidth="2.5" />
						</g>
					) }
				</svg>
			) }
			<Tooltip
				tip={
					hover !== null && pts[ hover ]
						? {
								x: Math.max( 80, Math.min( pts[ hover ][ 0 ], width - 80 ) ),
								y: Math.max( 56, pts[ hover ][ 1 ] - 14 ),
								content: (
									<>
										<strong>{ data[ hover ].label }</strong>
										<span className="wvm-a-ctip__row">
											<i style={ { background: color } } />
											{ name }
											<b>{ data[ hover ].display ?? format( data[ hover ].value ) }</b>
										</span>
									</>
								),
						  }
						: null
				}
			/>
		</div>
	);
}

/* ---------- donut ---------- */

export function Donut( { data, size = 168, centerLabel, centerValue }: { data: { label: string; value: number; display?: string }[]; size?: number; centerLabel?: string; centerValue?: ReactNode } ) {
	const [ hover, setHover ] = useState< number | null >( null );
	const total = data.reduce( ( a, d ) => a + d.value, 0 );
	const r = size / 2 - 10;
	const stroke = 18;
	const c = 2 * Math.PI * r;
	let acc = 0;
	const gap = data.filter( ( d ) => d.value > 0 ).length > 1 ? 2 : 0;
	return (
		<div className="wvm-a-donut">
			<div className="wvm-a-donut__ring" style={ { width: size, height: size } }>
				<svg width={ size } height={ size } viewBox={ `0 0 ${ size } ${ size }` } role="img">
					<circle cx={ size / 2 } cy={ size / 2 } r={ r } fill="none" stroke="var(--wvm-neutral-bg)" strokeWidth={ stroke } />
					{ total > 0 &&
						data.map( ( d, i ) => {
							const len = ( d.value / total ) * c;
							const dash = Math.max( 0, len - gap );
							const el = d.value > 0 ? (
								<circle
									key={ i }
									cx={ size / 2 }
									cy={ size / 2 }
									r={ r }
									fill="none"
									stroke={ SERIES[ i % SERIES.length ] }
									strokeWidth={ hover === i ? stroke + 4 : stroke }
									strokeDasharray={ `${ dash } ${ c - dash }` }
									strokeDashoffset={ -acc }
									transform={ `rotate(-90 ${ size / 2 } ${ size / 2 })` }
									onMouseEnter={ () => setHover( i ) }
									onMouseLeave={ () => setHover( null ) }
									style={ { transition: 'stroke-width .15s' } }
								/>
							) : null;
							acc += len;
							return el;
						} ) }
				</svg>
				<div className="wvm-a-donut__center">
					<strong>{ hover !== null ? data[ hover ].display ?? data[ hover ].value : centerValue ?? total }</strong>
					<span>{ hover !== null ? data[ hover ].label : centerLabel }</span>
				</div>
			</div>
			<ul className="wvm-a-donut__legend">
				{ data.map( ( d, i ) => (
					<li key={ i } onMouseEnter={ () => setHover( i ) } onMouseLeave={ () => setHover( null ) } className={ hover === i ? 'is-hover' : '' }>
						<i style={ { background: SERIES[ i % SERIES.length ] } } />
						<span>{ d.label }</span>
						<b>{ d.display ?? d.value }</b>
						<em>{ total ? Math.round( ( d.value / total ) * 100 ) : 0 }%</em>
					</li>
				) ) }
			</ul>
		</div>
	);
}

/* ---------- horizontal bars ---------- */

export function HBars( { data, color = SERIES[ 0 ] }: { data: { label: ReactNode; value: number; display?: ReactNode; sub?: ReactNode }[]; color?: string } ) {
	const max = Math.max( 1, ...data.map( ( d ) => d.value ) );
	return (
		<ul className="wvm-a-hbars">
			{ data.map( ( d, i ) => (
				<li key={ i }>
					<div className="wvm-a-hbars__top">
						<span className="wvm-a-hbars__label">{ d.label }</span>
						<span className="wvm-a-hbars__value">
							{ d.display ?? d.value }
							{ d.sub && <em>{ d.sub }</em> }
						</span>
					</div>
					<div className="wvm-a-hbars__track">
						<span style={ { width: `${ Math.max( 2, ( d.value / max ) * 100 ) }%`, background: color } } />
					</div>
				</li>
			) ) }
		</ul>
	);
}

export function Legend( { items }: { items: { label: string; color: string }[] } ) {
	return (
		<div className="wvm-a-legend">
			{ items.map( ( it ) => (
				<span key={ it.label }>
					<i style={ { background: it.color } } />
					{ it.label }
				</span>
			) ) }
		</div>
	);
}

/** Ring gauge for a single percentage. */
export function Gauge( { value, label, color = SERIES[ 0 ] }: { value: number; label?: string; color?: string } ) {
	const size = 132;
	const r = 52;
	const c = 2 * Math.PI * r;
	const pct = Math.max( 0, Math.min( 100, value ) );
	const [ anim, setAnim ] = useState( 0 );
	useEffect( () => {
		const t = setTimeout( () => setAnim( pct ), 30 );
		return () => clearTimeout( t );
	}, [ pct ] );
	return (
		<div className="wvm-a-gauge" style={ { width: size, height: size } }>
			<svg width={ size } height={ size }>
				<circle cx={ size / 2 } cy={ size / 2 } r={ r } fill="none" stroke="var(--wvm-neutral-bg)" strokeWidth="12" />
				<circle cx={ size / 2 } cy={ size / 2 } r={ r } fill="none" stroke={ color } strokeWidth="12" strokeLinecap="round" strokeDasharray={ `${ ( anim / 100 ) * c } ${ c }` } transform={ `rotate(-90 ${ size / 2 } ${ size / 2 })` } style={ { transition: 'stroke-dasharray .6s var(--wvm-ease)' } } />
			</svg>
			<div className="wvm-a-gauge__center">
				<strong>{ Math.round( pct * 10 ) / 10 }%</strong>
				{ label && <span>{ label }</span> }
			</div>
		</div>
	);
}
