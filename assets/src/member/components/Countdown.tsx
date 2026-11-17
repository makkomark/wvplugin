import { useNow } from '../hooks/useMisc';
import { cx } from '../lib/util';

export function timeLeft( target: number, now: number ) {
	const diff = Math.max( 0, target - now );
	const s = Math.floor( diff / 1000 );
	return { total: diff, days: Math.floor( s / 86400 ), hours: Math.floor( ( s % 86400 ) / 3600 ), minutes: Math.floor( ( s % 3600 ) / 60 ), seconds: s % 60 };
}

/** Compact text countdown: "2d 4h", "3h 12m", "12m 05s", or "Live now". */
export function countdownText( iso: string, now: number, endIso?: string ): string {
	const start = new Date( iso ).getTime();
	if ( now >= start ) {
		const end = endIso ? new Date( endIso ).getTime() : start;
		return now <= end ? 'Live now' : 'Ended';
	}
	const t = timeLeft( start, now );
	if ( t.days > 0 ) {
		return `in ${ t.days }d ${ t.hours }h`;
	}
	if ( t.hours > 0 ) {
		return `in ${ t.hours }h ${ t.minutes }m`;
	}
	return `in ${ t.minutes }m ${ String( t.seconds ).padStart( 2, '0' ) }s`;
}

/** Segmented countdown (days / hrs / min / sec tiles). */
export function Countdown( { to, light, endsAt }: { to: string; light?: boolean; endsAt?: string } ) {
	const now = useNow( 1000 );
	const start = new Date( to ).getTime();
	if ( now >= start ) {
		const ended = endsAt && now > new Date( endsAt ).getTime();
		return <span className={ cx( 'wvm-live', ended && 'is-ended' ) }>{ ended ? 'Ended' : 'Live now' }</span>;
	}
	const t = timeLeft( start, now );
	const units: [ number, string ][] = [
		[ t.days, 'days' ],
		[ t.hours, 'hrs' ],
		[ t.minutes, 'min' ],
		[ t.seconds, 'sec' ],
	];
	return (
		<div className={ cx( 'wvm-countdown', light && 'wvm-countdown--light' ) } role="timer" aria-label={ countdownText( to, now ) }>
			{ units.map( ( [ v, l ] ) => (
				<div key={ l } className="wvm-countdown__unit" aria-hidden="true">
					<span className="wvm-countdown__num">{ String( v ).padStart( 2, '0' ) }</span>
					<span className="wvm-countdown__lbl">{ l }</span>
				</div>
			) ) }
		</div>
	);
}
