/* Minimal stroke icon set (24px grid, 1.75 stroke) so the admin has one consistent icon language. */
import type { CSSProperties } from 'react';

const P: Record< string, string[] > = {
	home: [ 'p:m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z', 'p:M9 22V12h6v10' ],
	users: [ 'p:M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2', 'c:9,7,4', 'p:M22 21v-2a4 4 0 0 0-3-3.87', 'p:M16 3.13a4 4 0 0 1 0 7.75' ],
	card: [ 'r:2,5,20,14,2', 'p:M2 10h20' ],
	layers: [ 'p:m12 2 10 5-10 5L2 7z', 'p:m2 17 10 5 10-5', 'p:m2 12 10 5 10-5' ],
	calendar: [ 'r:3,4,18,18,2', 'p:M16 2v4', 'p:M8 2v4', 'p:M3 10h18' ],
	building: [ 'r:4,2,16,20,2', 'p:M9 22v-4h6v4', 'p:M8 6h.01', 'p:M16 6h.01', 'p:M12 6h.01', 'p:M12 10h.01', 'p:M12 14h.01', 'p:M16 10h.01', 'p:M16 14h.01', 'p:M8 10h.01', 'p:M8 14h.01' ],
	lock: [ 'r:3,11,18,11,2', 'p:M7 11V7a5 5 0 0 1 10 0v4' ],
	chart: [ 'p:M3 3v18h18', 'p:M18 17V9', 'p:M13 17V5', 'p:M8 17v-3' ],
	list: [ 'p:M8 6h13', 'p:M8 12h13', 'p:M8 18h13', 'p:M3 6h.01', 'p:M3 12h.01', 'p:M3 18h.01' ],
	sliders: [ 'p:M4 21v-7', 'p:M4 10V3', 'p:M12 21v-9', 'p:M12 8V3', 'p:M20 21v-5', 'p:M20 12V3', 'p:M1 14h6', 'p:M9 8h6', 'p:M17 16h6' ],
	search: [ 'c:11,11,8', 'p:m21 21-4.3-4.3' ],
	plus: [ 'p:M12 5v14', 'p:M5 12h14' ],
	download: [ 'p:M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4', 'p:M7 10l5 5 5-5', 'p:M12 15V3' ],
	upload: [ 'p:M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4', 'p:M17 8l-5-5-5 5', 'p:M12 3v12' ],
	x: [ 'p:M18 6 6 18', 'p:m6 6 12 12' ],
	chevronRight: [ 'p:m9 18 6-6-6-6' ],
	chevronLeft: [ 'p:m15 18-6-6 6-6' ],
	chevronDown: [ 'p:m6 9 6 6 6-6' ],
	check: [ 'p:M20 6 9 17l-5-5' ],
	alert: [ 'p:m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3', 'p:M12 9v4', 'p:M12 17h.01' ],
	info: [ 'c:12,12,10', 'p:M12 16v-4', 'p:M12 8h.01' ],
	external: [ 'p:M15 3h6v6', 'p:M10 14 21 3', 'p:M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6' ],
	copy: [ 'r:9,9,13,13,2', 'p:M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1' ],
	mail: [ 'r:2,4,20,16,2', 'p:m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7' ],
	link: [ 'p:M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71', 'p:M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71' ],
	refresh: [ 'p:M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8', 'p:M21 3v5h-5', 'p:M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16', 'p:M8 16H3v5' ],
	more: [ 'c:12,12,1', 'c:19,12,1', 'c:5,12,1' ],
	grip: [ 'c:9,5,1', 'c:9,12,1', 'c:9,19,1', 'c:15,5,1', 'c:15,12,1', 'c:15,19,1' ],
	trash: [ 'p:M3 6h18', 'p:M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6', 'p:M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2' ],
	edit: [ 'p:M12 20h9', 'p:M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z' ],
	clock: [ 'c:12,12,10', 'p:M12 6v6l4 2' ],
	user: [ 'c:12,8,5', 'p:M20 21a8 8 0 0 0-16 0' ],
	video: [ 'p:m22 8-6 4 6 4V8Z', 'r:2,6,14,12,2' ],
	shield: [ 'p:M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10' ],
	zap: [ 'p:M13 2 3 14h9l-1 8 10-12h-9l1-8z' ],
	arrowUpRight: [ 'p:M7 17 17 7', 'p:M7 7h10v10' ],
	arrowRight: [ 'p:M5 12h14', 'p:m12 5 7 7-7 7' ],
	filter: [ 'p:M22 3H2l8 9.46V19l4 2v-8.54L22 3z' ],
	bookmark: [ 'p:m19 21-7-4-7 4V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16z' ],
	eye: [ 'p:M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z', 'c:12,12,3' ],
	login: [ 'p:M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4', 'p:m10 17 5-5-5-5', 'p:M15 12H3' ],
	star: [ 'p:m12 2 3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-5.18 2.75L8 13.87 2 9l6.91-1.01z' ],
	grid: [ 'r:3,3,7,7,1.5', 'r:14,3,7,7,1.5', 'r:14,14,7,7,1.5', 'r:3,14,7,7,1.5' ],
	send: [ 'p:m22 2-7 20-4-9-9-4Z', 'p:M22 2 11 13' ],
	tag: [ 'p:M12 2H2v10l9.29 9.29c.94.94 2.48.94 3.42 0l6.58-6.58c.94-.94.94-2.48 0-3.42L12 2Z', 'p:M7 7h.01' ],
	pause: [ 'r:6,4,4,16,1', 'r:14,4,4,16,1' ],
	play: [ 'p:m6 3 14 9-14 9z' ],
	restore: [ 'p:M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8', 'p:M3 3v5h5' ],
	archive: [ 'r:2,3,20,5,1', 'p:M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8', 'p:M10 12h4' ],
	sparkles: [ 'p:M12 3l1.9 5.8L20 10l-6.1 1.2L12 17l-1.9-5.8L4 10l6.1-1.2z', 'p:M19 15l.7 2.3L22 18l-2.3.7L19 21l-.7-2.3L16 18l2.3-.7z' ],
	globe: [ 'c:12,12,10', 'p:M2 12h20', 'p:M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z' ],
	receipt: [ 'p:M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z', 'p:M16 8h-6a2 2 0 1 0 0 4h4a2 2 0 1 1 0 4H8', 'p:M12 17.5v-11' ],
	activity: [ 'p:M22 12h-4l-3 9L9 3l-3 9H2' ],
	message: [ 'p:M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z' ],
	inbox: [ 'p:M22 12h-6l-2 3h-4l-2-3H2', 'p:M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z' ],
	key: [ 'c:7.5,15.5,5.5', 'p:m21 2-9.6 9.6', 'p:m15.5 7.5 3 3L22 7l-3-3' ],
	wand: [ 'p:m15 4-2 2', 'p:m21 10-2 2', 'p:M3 21l12-12', 'p:M14 3v2', 'p:M20 9h2' ],
	database: [ 'p:M3 5c0-1.66 4-3 9-3s9 1.34 9 3-4 3-9 3-9-1.34-9-3', 'p:M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5', 'p:M3 12c0 1.66 4 3 9 3s9-1.34 9-3' ],
};

export type IconName = keyof typeof P;

export function Icon( { name, size = 18, className, style, strokeWidth = 1.75 }: { name: IconName | string; size?: number; className?: string; style?: CSSProperties; strokeWidth?: number } ) {
	const parts = P[ name ] || P.info;
	return (
		<svg className={ 'wvm-a-icon' + ( className ? ' ' + className : '' ) } style={ style } width={ size } height={ size } viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={ strokeWidth } strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" focusable="false">
			{ parts.map( ( d, i ) => {
				const [ t, v ] = [ d.slice( 0, 1 ), d.slice( 2 ) ];
				if ( t === 'c' ) {
					const [ cx, cy, r ] = v.split( ',' ).map( Number );
					return <circle key={ i } cx={ cx } cy={ cy } r={ r } />;
				}
				if ( t === 'r' ) {
					const [ x, y, w, h, rx ] = v.split( ',' ).map( Number );
					return <rect key={ i } x={ x } y={ y } width={ w } height={ h } rx={ rx } />;
				}
				return <path key={ i } d={ v } />;
			} ) }
		</svg>
	);
}
