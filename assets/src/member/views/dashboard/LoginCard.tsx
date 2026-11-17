/* Logged-out dashboard: polished login card. */
import { config } from '../../lib/config';
import { LoginForm } from '../../components/Auth';
import { Brand } from '../../components/Brand';
import { Banner } from '../../components/Feedback';
import { Icon } from '../../components/Icon';

export function LoginCard( { notice }: { notice?: string | null } ) {
	return (
		<div className="wvm-auth">
			<div className="wvm-auth__card">
				<div className="wvm-auth__brand">
					<Brand />
				</div>
				<h2 className="wvm-h1 wvm-center">Welcome back</h2>
				<p className="wvm-muted wvm-center wvm-mb-6">Log in to see your membership, workshops and receipts.</p>
				{ notice && (
					<Banner tone="info" className="wvm-mb-4">
						{ notice === 'closed' ? 'That payment link has already been used or the order is closed. Log in to see your membership.' : 'That payment link is no longer valid. Log in to pay from your dashboard.' }
					</Banner>
				) }
				<LoginForm />
				<div className="wvm-auth__divider"><span>New here?</span></div>
				<a className="wvm-auth__cta" href={ config.pricingUrl }>
					<span className="wvm-auth__cta-icon" aria-hidden="true"><Icon name="sparkles" size={ 18 } /></span>
					<span>
						<strong>Become a member</strong>
						<span className="wvm-muted wvm-small">Start free or pick a plan</span>
					</span>
					<Icon name="arrow-right" size={ 18 } />
				</a>
			</div>
			<p className="wvm-auth__foot wvm-small wvm-muted">
				<Icon name="lock" size={ 14 } /> Secure login{ config.supportEmail && <> · Trouble? <a className="wvm-link" href={ `mailto:${ config.supportEmail }` }>{ config.supportEmail }</a></> }
			</p>
		</div>
	);
}
