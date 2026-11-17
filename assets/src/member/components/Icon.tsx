/* Inline SVG icon set (24px grid, 2px stroke, round joins). */
import type { CSSProperties } from 'react';

const PATHS = {
	home: <><path d="M3 10.5 12 3l9 7.5" /><path d="M5 9.5V20a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1V9.5" /></>,
	calendar: <><rect x="3" y="4.5" width="18" height="17" rx="3" /><path d="M16 2.5v4M8 2.5v4M3 10h18" /></>,
	card: <><rect x="2" y="5" width="20" height="14" rx="3" /><path d="M2 10h20M6 15h4" /></>,
	user: <><circle cx="12" cy="8" r="4" /><path d="M4 21v-1a6 6 0 0 1 6-6h4a6 6 0 0 1 6 6v1" /></>,
	users: <><circle cx="9" cy="8" r="3.5" /><path d="M2 20v-.5A5.5 5.5 0 0 1 7.5 14h3a5.5 5.5 0 0 1 5.5 5.5v.5" /><path d="M16 4.6a3.5 3.5 0 0 1 0 6.8M18.5 14a5.5 5.5 0 0 1 3.5 5.1v.9" /></>,
	bell: <><path d="M18 8.5a6 6 0 0 0-12 0c0 6.5-3 8.5-3 8.5h18s-3-2-3-8.5" /><path d="M13.7 21a2 2 0 0 1-3.4 0" /></>,
	check: <path d="M20 6 9 17l-5-5" />,
	x: <path d="M18 6 6 18M6 6l12 12" />,
	'arrow-right': <path d="M5 12h14M13 6l6 6-6 6" />,
	download: <><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" /><path d="m7 10 5 5 5-5M12 15V3" /></>,
	external: <><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6" /><path d="M15 3h6v6M10 14 21 3" /></>,
	clock: <><circle cx="12" cy="12" r="9.5" /><path d="M12 7v5l3 2" /></>,
	lock: <><rect x="4" y="11" width="16" height="10" rx="2.5" /><path d="M8 11V7a4 4 0 0 1 8 0v4" /></>,
	sparkles: <><path d="m12 3 1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z" /><path d="m19 15 .7 1.8 1.8.7-1.8.7L19 20l-.7-1.8-1.8-.7 1.8-.7z" /></>,
	logout: <><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" /><path d="m16 17 5-5-5-5M21 12H9" /></>,
	shield: <><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" /><path d="m9 12 2 2 4-4" /></>,
	plus: <path d="M12 5v14M5 12h14" />,
	trash: <><path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2" /><path d="m19 6-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6M10 11v6M14 11v6" /></>,
	info: <><circle cx="12" cy="12" r="9.5" /><path d="M12 16v-4.5M12 8h.01" /></>,
	alert: <><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z" /><path d="M12 9v4M12 17h.01" /></>,
	'chevron-down': <path d="m6 9 6 6 6-6" />,
	'chevron-right': <path d="m9 6 6 6-6 6" />,
	'chevron-left': <path d="m15 6-6 6 6 6" />,
	play: <><circle cx="12" cy="12" r="9.5" /><path d="m10 8.5 5 3.5-5 3.5z" /></>,
	video: <><rect x="2" y="6" width="14" height="12" rx="3" /><path d="m16 10 6-3.5v11L16 14" /></>,
	book: <><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20" /><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z" /></>,
	mail: <><rect x="2" y="4" width="20" height="16" rx="3" /><path d="m2.5 6.5 9.5 7 9.5-7" /></>,
	globe: <><circle cx="12" cy="12" r="9.5" /><path d="M2.5 12h19M12 2.5a14.5 14.5 0 0 1 0 19M12 2.5a14.5 14.5 0 0 0 0 19" /></>,
	monitor: <><rect x="2" y="3" width="20" height="14" rx="2.5" /><path d="M8 21h8M12 17v4" /></>,
	phone: <><rect x="6" y="2" width="12" height="20" rx="3" /><path d="M11 18h2" /></>,
	building: <><rect x="4" y="2" width="16" height="20" rx="2" /><path d="M9 22v-4h6v4M8 6h.01M12 6h.01M16 6h.01M8 10h.01M12 10h.01M16 10h.01M8 14h.01M12 14h.01M16 14h.01" /></>,
	message: <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" />,
	refresh: <><path d="M21 12a9 9 0 0 1-15.5 6.2L3 16" /><path d="M3 12a9 9 0 0 1 15.5-6.2L21 8" /><path d="M21 3v5h-5M3 21v-5h5" /></>,
	star: <path d="m12 2.5 2.9 6 6.6.9-4.8 4.6 1.2 6.5L12 17.4l-5.9 3.1 1.2-6.5-4.8-4.6 6.6-.9z" />,
	gift: <><rect x="3" y="8" width="18" height="4" rx="1" /><path d="M12 8v13M19 12v7a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-7M7.5 8a2.5 2.5 0 0 1 0-5C11 3 12 8 12 8s1-5 4.5-5a2.5 2.5 0 0 1 0 5" /></>,
	eye: <><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z" /><circle cx="12" cy="12" r="3" /></>,
	'eye-off': <><path d="M10.6 5.1A10.4 10.4 0 0 1 12 5c6.5 0 10 7 10 7a17.6 17.6 0 0 1-2.2 3.2M6.6 6.6C3.9 8.4 2 12 2 12s3.5 7 10 7a9.7 9.7 0 0 0 5.4-1.6" /><path d="m2 2 20 20M9.9 9.9a3 3 0 0 0 4.2 4.2" /></>,
	copy: <><rect x="9" y="9" width="12" height="12" rx="2.5" /><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1" /></>,
	zap: <path d="M13 2 3 14h9l-1 8 10-12h-9z" />,
	settings: <><circle cx="12" cy="12" r="3" /><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z" /></>,
} as const;

export type IconName = keyof typeof PATHS;

interface Props {
	name: IconName;
	size?: number;
	className?: string;
	style?: CSSProperties;
	label?: string;
	strokeWidth?: number;
}

export function Icon( { name, size = 20, className, style, label, strokeWidth = 2 }: Props ) {
	return (
		<svg
			className={ 'wvm-icon' + ( className ? ' ' + className : '' ) }
			width={ size }
			height={ size }
			viewBox="0 0 24 24"
			fill="none"
			stroke="currentColor"
			strokeWidth={ strokeWidth }
			strokeLinecap="round"
			strokeLinejoin="round"
			style={ style }
			role={ label ? 'img' : undefined }
			aria-label={ label }
			aria-hidden={ label ? undefined : true }
			focusable="false"
		>
			{ PATHS[ name ] }
		</svg>
	);
}
