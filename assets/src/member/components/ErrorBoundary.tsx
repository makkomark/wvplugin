import { Component } from '@wordpress/element';
import type { ReactNode, ErrorInfo } from 'react';
import { EmptyState } from './Feedback';

interface Props { children: ReactNode; compact?: boolean }
interface State { failed: boolean }

export class ErrorBoundary extends Component< Props, State > {
	state: State = { failed: false };

	static getDerivedStateFromError(): State {
		return { failed: true };
	}

	componentDidCatch( error: Error, info: ErrorInfo ): void {
		// eslint-disable-next-line no-console
		console.error( '[WitsValley] UI error', error, info?.componentStack );
	}

	render() {
		if ( this.state.failed ) {
			return (
				<div className="wvm-card wvm-card--pad-md">
					<EmptyState
						icon="alert"
						title="Something went wrong on this screen"
						compact={ this.props.compact }
						action={
							<button type="button" className="wvm-btn wvm-btn--secondary wvm-btn--sm" onClick={ () => window.location.reload() }>
								<span className="wvm-btn__label">Reload page</span>
							</button>
						}
					>
						Please reload the page. If it keeps happening, contact support.
					</EmptyState>
				</div>
			);
		}
		return this.props.children;
	}
}
