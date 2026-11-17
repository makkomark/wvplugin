import { ErrorBoundary } from './components/ErrorBoundary';
import { ToastProvider } from './components/Toast';
import { PricingView } from './views/pricing/PricingView';
import { EnterpriseView } from './views/enterprise/EnterpriseView';
import { DashboardView } from './views/dashboard/DashboardView';

export type View = 'dashboard' | 'pricing' | 'enterprise';

export function App( { view, plan }: { view: View; plan?: string } ) {
	let content;
	switch ( view ) {
		case 'pricing':
			content = <PricingView preselect={ plan } />;
			break;
		case 'enterprise':
			content = <EnterpriseView />;
			break;
		default:
			content = <DashboardView />;
	}
	return (
		<ToastProvider>
			<ErrorBoundary>{ content }</ErrorBoundary>
		</ToastProvider>
	);
}
