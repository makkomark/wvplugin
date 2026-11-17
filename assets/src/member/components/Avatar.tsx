import { useState } from '@wordpress/element';
import { initials } from '../../shared/format';
import { cx } from '../lib/util';

export function Avatar( { name, url, size = 36, className }: { name: string; url?: string | null; size?: number; className?: string } ) {
	const [ broken, setBroken ] = useState( false );
	// Gravatar "mystery person" defaults look generic — prefer initials unless a real image loads.
	const useImg = url && ! broken && ! /[?&]d=mm\b|[?&]d=mystery\b/.test( url );
	return (
		<span className={ cx( 'wvm-avatar', className ) } style={ { width: size, height: size, fontSize: Math.round( size * 0.38 ) } } aria-hidden="true">
			{ useImg ? <img src={ url as string } alt="" onError={ () => setBroken( true ) } width={ size } height={ size } /> : initials( name || '?' ) }
		</span>
	);
}
