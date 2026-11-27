/*
 * WitsValley membership popup. Plain TypeScript (no React) so it stays tiny on every blog page.
 * Config comes from PHP (src/Domain/Popup/PopupService::forRequest) as window.wvmPopup.
 */
import './popup.css';

interface PopupConfig {
	kind: 'join' | 'upgrade';
	mode: 'join' | 'email';
	preview: boolean;
	restUrl: string;
	freePlanId: number;
	siteName: string;
	copy: {
		eyebrow: string;
		headline: string;
		headlineB: string;
		body: string;
		bullets: string[];
		button: string;
		emailButton: string;
		note: string;
		imageUrl: string;
		bookUrl: string;
		successTitle: string;
		successBody: string;
		emailSuccess: string;
		upEyebrow: string;
		upHeadline: string;
		upBody: string;
		upButton: string;
	};
	triggers: { delay: number; scroll: number; exitIntent: boolean; paywall: boolean; minPageviews: number; dismissDays: number };
	urls: { terms: string; privacy: string; login: string; dashboard: string; upgrade: string };
}

type Variant = 'a' | 'b' | 'upgrade';
type Attrs = Record< string, string | boolean | ( ( e: Event ) => void ) | undefined >;

const cfg = ( window as unknown as { wvmPopup?: PopupConfig } ).wvmPopup;

/* ---------- tiny DOM helper (text only via textContent: copy is never parsed as HTML) ---------- */
function h( tag: string, attrs: Attrs = {}, ...children: ( Node | string | false | null | undefined )[] ): HTMLElement {
	const el = document.createElement( tag );
	for ( const [ k, v ] of Object.entries( attrs ) ) {
		if ( v === undefined || v === false ) {
			continue;
		}
		if ( typeof v === 'function' ) {
			el.addEventListener( k.replace( /^on/, '' ).toLowerCase(), v as EventListener );
		} else if ( v === true ) {
			el.setAttribute( k, '' );
		} else {
			el.setAttribute( k, v );
		}
	}
	for ( const c of children ) {
		if ( c ) {
			el.append( c );
		}
	}
	return el;
}

const svg = ( d: string, size = 20 ) => {
	const s = document.createElementNS( 'http://www.w3.org/2000/svg', 'svg' );
	s.setAttribute( 'viewBox', '0 0 24 24' );
	s.setAttribute( 'width', String( size ) );
	s.setAttribute( 'height', String( size ) );
	s.setAttribute( 'fill', 'none' );
	s.setAttribute( 'stroke', 'currentColor' );
	s.setAttribute( 'stroke-width', '2' );
	s.setAttribute( 'stroke-linecap', 'round' );
	s.setAttribute( 'stroke-linejoin', 'round' );
	s.setAttribute( 'aria-hidden', 'true' );
	d.split( '|' ).forEach( ( p ) => {
		const path = document.createElementNS( 'http://www.w3.org/2000/svg', 'path' );
		path.setAttribute( 'd', p );
		s.append( path );
	} );
	return s;
};
const ICON = {
	close: 'M18 6 6 18|M6 6l12 12',
	check: 'M20 6 9 17l-5-5',
	book: 'M4 19.5A2.5 2.5 0 0 1 6.5 17H20|M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z',
	download: 'M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4|M7 10l5 5 5-5|M12 15V3',
	arrow: 'M5 12h14|M12 5l7 7-7 7',
	eye: 'M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z|M12 9a3 3 0 1 0 0 6 3 3 0 0 0 0-6z',
	spark: 'M12 3l1.9 5.8L20 10l-6.1 1.2L12 17l-1.9-5.8L4 10l6.1-1.2z',
};

/* ---------- storage (any of it may be blocked: private mode, cookie settings) ---------- */
const store = {
	get( k: string, session = false ): string | null {
		try {
			return ( session ? sessionStorage : localStorage ).getItem( k );
		} catch {
			return null;
		}
	},
	set( k: string, v: string, session = false ): void {
		try {
			( session ? sessionStorage : localStorage ).setItem( k, v );
		} catch {
			/* ignore */
		}
	},
};

function send( path: string, body: Record< string, unknown > ): Promise< { ok: boolean; data: Record< string, unknown > | null; error: { message?: string; fields?: Record< string, string > } | null } > {
	return fetch( cfg!.restUrl + path, {
		method: 'POST',
		credentials: 'same-origin',
		headers: { 'Content-Type': 'application/json' },
		body: JSON.stringify( body ),
	} )
		.then( async ( r ) => {
			const j = await r.json().catch( () => ( {} ) );
			return { ok: r.ok, data: j?.data ?? null, error: r.ok ? null : j?.error ?? { message: 'Something went wrong. Please try again.' } };
		} )
		.catch( () => ( { ok: false, data: null, error: { message: 'You seem to be offline. Please try again.' } } ) );
}

function event( name: 'view' | 'close' | 'upgrade_click', variant: Variant ): void {
	if ( cfg!.preview ) {
		return;
	}
	void send( 'public/popup/event', { event: name, variant } );
}

function variantFor( c: PopupConfig ): Variant {
	if ( c.kind === 'upgrade' ) {
		return 'upgrade';
	}
	if ( ! c.copy.headlineB ) {
		return 'a';
	}
	const saved = store.get( 'wvm_popup_ab' );
	if ( saved === 'a' || saved === 'b' ) {
		return saved;
	}
	const v: Variant = Math.random() < 0.5 ? 'a' : 'b';
	store.set( 'wvm_popup_ab', v );
	return v;
}

/* ---------- the popup ---------- */
function build( c: PopupConfig, variant: Variant ) {
	let lastFocus: Element | null = null;
	const titleId = 'wvm-pop-title';
	const card = h( 'div', { class: 'wvm-pop__card', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': titleId } );
	const overlay = h( 'div', { class: 'wvm-pop', 'data-kind': c.kind }, card );

	const close = ( record = true ) => {
		overlay.classList.remove( 'is-open' );
		document.documentElement.classList.remove( 'wvm-pop-lock' );
		document.removeEventListener( 'keydown', onKey );
		window.setTimeout( () => overlay.remove(), 250 );
		if ( record ) {
			event( 'close', variant );
			if ( c.triggers.dismissDays > 0 ) {
				store.set( 'wvm_popup_dismissed', String( Date.now() ) );
			}
		}
		( lastFocus as HTMLElement | null )?.focus?.();
	};
	const onKey = ( e: KeyboardEvent ) => {
		if ( e.key === 'Escape' ) {
			close();
		}
		if ( e.key === 'Tab' ) {
			const f = Array.from( card.querySelectorAll< HTMLElement >( 'a[href],button:not([disabled]),input:not([type=hidden]):not([tabindex="-1"])' ) ).filter( ( x ) => x.offsetParent !== null );
			if ( ! f.length ) {
				return;
			}
			const first = f[ 0 ];
			const last = f[ f.length - 1 ];
			if ( e.shiftKey && document.activeElement === first ) {
				e.preventDefault();
				last.focus();
			} else if ( ! e.shiftKey && document.activeElement === last ) {
				e.preventDefault();
				first.focus();
			}
		}
	};
	overlay.addEventListener( 'mousedown', ( e ) => {
		if ( e.target === overlay ) {
			close();
		}
	} );

	const closeBtn = h( 'button', { type: 'button', class: 'wvm-pop__close', 'aria-label': 'Close', onClick: () => close() }, svg( ICON.close, 18 ) );

	const art = h(
		'div',
		{ class: 'wvm-pop__art' + ( c.copy.imageUrl ? ' has-image' : '' ) },
		c.copy.imageUrl
			? h( 'img', { src: c.copy.imageUrl, alt: '', loading: 'lazy', decoding: 'async' } )
			: h( 'div', { class: 'wvm-pop__book', 'aria-hidden': 'true' }, h( 'span', { class: 'wvm-pop__book-mark' }, svg( c.kind === 'upgrade' ? ICON.spark : ICON.book, 28 ) ), h( 'strong', {}, c.siteName ), h( 'em', {}, c.kind === 'upgrade' ? c.copy.upEyebrow : c.copy.eyebrow ) )
	);

	const body = h( 'div', { class: 'wvm-pop__body' } );
	card.append( closeBtn, art, body );

	const fill = ( ...nodes: ( Node | string | false | null | undefined )[] ) => {
		body.replaceChildren( ...nodes.filter( ( n ): n is Node => n instanceof Node ) );
	};

	/* -- upgrade (logged-in free members) -- */
	const renderUpgrade = () => {
		fill(
			c.copy.upEyebrow && h( 'span', { class: 'wvm-pop__eyebrow' }, c.copy.upEyebrow ),
			h( 'h2', { class: 'wvm-pop__title', id: titleId }, c.copy.upHeadline ),
			c.copy.upBody && h( 'p', { class: 'wvm-pop__text' }, c.copy.upBody ),
			h(
				'div',
				{ class: 'wvm-pop__actions' },
				h( 'a', { class: 'wvm-pop__btn', href: c.urls.upgrade, onClick: () => event( 'upgrade_click', variant ) }, c.copy.upButton, svg( ICON.arrow, 18 ) ),
				h( 'button', { type: 'button', class: 'wvm-pop__ghost', onClick: () => close() }, 'Not now' )
			)
		);
	};

	/* -- success -- */
	const renderSuccess = ( joined: boolean ) => {
		fill(
			h( 'span', { class: 'wvm-pop__done' }, svg( ICON.check, 26 ) ),
			h( 'h2', { class: 'wvm-pop__title', id: titleId }, joined ? c.copy.successTitle : 'Thank you!' ),
			h( 'p', { class: 'wvm-pop__text' }, joined ? c.copy.successBody : c.copy.emailSuccess ),
			h(
				'div',
				{ class: 'wvm-pop__actions is-stack' },
				joined && c.copy.bookUrl && h( 'a', { class: 'wvm-pop__btn', href: c.copy.bookUrl, target: '_blank', rel: 'noopener', download: '' }, svg( ICON.download, 18 ), 'Download your free book' ),
				joined
					? h( 'a', { class: c.copy.bookUrl ? 'wvm-pop__ghost' : 'wvm-pop__btn', href: c.urls.dashboard }, 'Go to my dashboard', svg( ICON.arrow, 18 ) )
					: h( 'button', { type: 'button', class: 'wvm-pop__btn', onClick: () => close( false ) }, 'Back to reading' )
			)
		);
		( body.querySelector( '.wvm-pop__btn' ) as HTMLElement | null )?.focus();
	};

	/* -- the offer form (join free, or email only) -- */
	const renderOffer = () => {
		const email = c.mode === 'email';
		const headline = variant === 'b' && c.copy.headlineB ? c.copy.headlineB : c.copy.headline;
		const errs: Record< string, HTMLElement > = {};
		const field = ( name: string, label: string, type: string, auto: string, extra: Attrs = {} ) => {
			const id = `wvm-pop-${ name }`;
			const input = h( 'input', { id, name, type, autocomplete: auto, required: true, class: 'wvm-pop__input', ...extra } ) as HTMLInputElement;
			errs[ name ] = h( 'span', { class: 'wvm-pop__err', id: `${ id }-err`, role: 'alert' } );
			input.setAttribute( 'aria-describedby', `${ id }-err` );
			const wrap = h( 'div', { class: 'wvm-pop__field' }, h( 'label', { for: id }, label ), input, errs[ name ] );
			if ( type === 'password' ) {
				const toggle = h( 'button', { type: 'button', class: 'wvm-pop__eye', 'aria-label': 'Show password', onClick: () => {
					input.type = input.type === 'password' ? 'text' : 'password';
					toggle.setAttribute( 'aria-label', input.type === 'password' ? 'Show password' : 'Hide password' );
				} }, svg( ICON.eye, 18 ) );
				wrap.classList.add( 'has-eye' );
				wrap.append( toggle );
			}
			return { wrap, input };
		};
		const name = field( 'name', 'First name', 'text', 'given-name' );
		const mail = field( 'email', 'Email', 'email', 'email', { inputmode: 'email' } );
		const pass = email ? null : field( 'password', 'Create a password', 'password', 'new-password', { minlength: '8' } );
		const trap = h( 'input', { type: 'text', name: 'website', tabindex: '-1', autocomplete: 'off', class: 'wvm-pop__hp', 'aria-hidden': 'true' } ) as HTMLInputElement;
		const banner = h( 'p', { class: 'wvm-pop__banner', role: 'alert', hidden: true } );
		const submit = h( 'button', { type: 'submit', class: 'wvm-pop__btn is-block' }, email ? c.copy.emailButton : c.copy.button, svg( ICON.arrow, 18 ) ) as HTMLButtonElement;

		const legal = h( 'p', { class: 'wvm-pop__legal' } );
		if ( ! email ) {
			legal.append( 'By joining you agree to the ' );
			legal.append( c.urls.terms ? h( 'a', { href: c.urls.terms, target: '_blank', rel: 'noopener' }, 'terms of membership' ) : 'terms of membership' );
			if ( c.urls.privacy ) {
				legal.append( ' and ', h( 'a', { href: c.urls.privacy, target: '_blank', rel: 'noopener' }, 'privacy policy' ) );
			}
			legal.append( '.' );
		} else {
			legal.append( 'We’ll email you the book and a short series of guides. Unsubscribe any time.' );
			if ( c.urls.privacy ) {
				legal.append( ' ', h( 'a', { href: c.urls.privacy, target: '_blank', rel: 'noopener' }, 'Privacy policy' ) );
			}
		}

		const setErr = ( k: string, msg: string ) => {
			if ( errs[ k ] ) {
				errs[ k ].textContent = msg;
				errs[ k ].parentElement?.classList.toggle( 'is-invalid', !! msg );
			}
		};

		const form = h( 'form', { class: 'wvm-pop__form', novalidate: true } );
		form.addEventListener( 'submit', async ( e ) => {
			e.preventDefault();
			banner.hidden = true;
			let bad = false;
			setErr( 'name', name.input.value.trim() ? '' : ( ( bad = true ), 'Tell us your name.' ) );
			setErr( 'email', /^\S+@\S+\.\S+$/.test( mail.input.value.trim() ) ? '' : ( ( bad = true ), 'Enter a valid email address.' ) );
			if ( pass ) {
				const p = pass.input.value;
				setErr( 'password', p.length >= 8 && /[A-Za-z]/.test( p ) && /\d/.test( p ) ? '' : ( ( bad = true ), 'At least 8 characters, with letters and numbers.' ) );
			}
			if ( bad ) {
				( form.querySelector( '.is-invalid input' ) as HTMLInputElement | null )?.focus();
				return;
			}
			if ( c.preview ) {
				renderSuccess( ! email );
				return;
			}
			submit.disabled = true;
			submit.classList.add( 'is-busy' );
			const res = email
				? await send( 'public/popup/lead', { name: name.input.value.trim(), email: mail.input.value.trim(), website: trap.value, variant } )
				: await send( 'public/signup', {
						name: name.input.value.trim(),
						email: mail.input.value.trim(),
						password: pass!.input.value,
						plan_id: c.freePlanId,
						terms: true,
						marketing_consent: false,
						website: trap.value,
						timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || undefined,
						source: 'popup',
						variant,
				  } );
			submit.disabled = false;
			submit.classList.remove( 'is-busy' );
			if ( res.ok ) {
				store.set( 'wvm_popup_done', '1' );
				renderSuccess( ! email );
				return;
			}
			const fields = res.error?.fields || {};
			let shown = false;
			for ( const [ k, msg ] of Object.entries( fields ) ) {
				if ( errs[ k ] ) {
					setErr( k, msg );
					shown = true;
				}
			}
			if ( fields.email && /already|log in/i.test( fields.email ) ) {
				errs.email.append( ' ', h( 'a', { href: c.urls.login }, 'Log in' ) );
			}
			if ( ! shown ) {
				banner.textContent = res.error?.message || 'Something went wrong. Please try again.';
				banner.hidden = false;
			}
		} );

		form.append( h( 'div', { class: 'wvm-pop__row' }, name.wrap, mail.wrap ), pass?.wrap || '', trap, banner, submit, legal );

		fill(
			c.copy.eyebrow && h( 'span', { class: 'wvm-pop__eyebrow' }, c.copy.eyebrow ),
			h( 'h2', { class: 'wvm-pop__title', id: titleId }, headline ),
			c.copy.body && h( 'p', { class: 'wvm-pop__text' }, c.copy.body ),
			c.copy.bullets.length > 0 && h( 'ul', { class: 'wvm-pop__list' }, ...c.copy.bullets.map( ( b ) => h( 'li', {}, h( 'span', { class: 'wvm-pop__tick' }, svg( ICON.check, 13 ) ), b ) ) ),
			form,
			h(
				'p',
				{ class: 'wvm-pop__foot' },
				c.copy.note && h( 'span', {}, c.copy.note ),
				! email && h( 'span', {}, 'Already a member? ', h( 'a', { href: c.urls.login }, 'Log in' ) )
			)
		);
	};

	if ( c.kind === 'upgrade' ) {
		renderUpgrade();
	} else {
		renderOffer();
	}

	return {
		open() {
			lastFocus = document.activeElement;
			document.body.append( overlay );
			document.documentElement.classList.add( 'wvm-pop-lock' );
			document.addEventListener( 'keydown', onKey );
			requestAnimationFrame( () => {
				overlay.classList.add( 'is-open' );
				// On touch screens focusing a field would throw the keyboard up before they've read the offer.
				const touch = window.matchMedia( '(pointer: coarse)' ).matches;
				const first = touch ? null : card.querySelector< HTMLElement >( 'input.wvm-pop__input, .wvm-pop__btn' );
				if ( first ) {
					first.focus( { preventScroll: true } );
				} else {
					card.setAttribute( 'tabindex', '-1' );
					card.focus( { preventScroll: true } );
				}
			} );
			event( 'view', variant );
		},
	};
}

/* ---------- when to show it ---------- */
function start( c: PopupConfig ) {
	const variant = variantFor( c );
	let shown = false;
	const popup = build( c, variant );
	const show = () => {
		if ( shown ) {
			return;
		}
		shown = true;
		store.set( 'wvm_popup_session', '1', true );
		popup.open();
	};

	if ( c.preview ) {
		window.setTimeout( show, 400 );
		return;
	}
	if ( c.kind === 'join' && store.get( 'wvm_popup_done' ) ) {
		return;
	}
	const dismissed = Number( store.get( 'wvm_popup_dismissed' ) || 0 );
	if ( dismissed && c.triggers.dismissDays > 0 && Date.now() - dismissed < c.triggers.dismissDays * 864e5 ) {
		return;
	}
	if ( store.get( 'wvm_popup_session', true ) ) {
		return;
	}
	const views = Number( store.get( 'wvm_popup_pv' ) || 0 ) + 1;
	store.set( 'wvm_popup_pv', String( views ) );

	// Hitting a members-only wall is the strongest moment, so it ignores the page-view minimum.
	const wall = c.triggers.paywall ? document.querySelector( '.wvm-paywall' ) : null;
	if ( wall && 'IntersectionObserver' in window ) {
		const io = new IntersectionObserver( ( entries ) => {
			if ( entries.some( ( x ) => x.isIntersecting ) ) {
				io.disconnect();
				window.setTimeout( show, 700 );
			}
		} );
		io.observe( wall );
	}
	if ( views < c.triggers.minPageviews ) {
		return;
	}
	if ( c.triggers.delay > 0 ) {
		window.setTimeout( show, c.triggers.delay * 1000 );
	}
	if ( c.triggers.scroll > 0 ) {
		const onScroll = () => {
			const doc = document.documentElement;
			const max = doc.scrollHeight - window.innerHeight;
			if ( max > 0 && ( window.scrollY / max ) * 100 >= c.triggers.scroll ) {
				window.removeEventListener( 'scroll', onScroll );
				show();
			}
		};
		window.addEventListener( 'scroll', onScroll, { passive: true } );
	}
	if ( c.triggers.exitIntent && window.matchMedia( '(pointer: fine)' ).matches ) {
		const onOut = ( e: MouseEvent ) => {
			if ( ! e.relatedTarget && e.clientY <= 0 ) {
				document.removeEventListener( 'mouseout', onOut );
				show();
			}
		};
		// Give people a moment on the page before reacting to the mouse leaving.
		window.setTimeout( () => document.addEventListener( 'mouseout', onOut ), 4000 );
	}
}

if ( cfg ) {
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', () => start( cfg ) );
	} else {
		start( cfg );
	}
}
