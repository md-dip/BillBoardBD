import usePageTitle from '../hooks/usePageTitle';
import './HowItWorks.css';

export default function HowItWorks() {
    usePageTitle('How it works');

    return (
        <div className="how-it-works-page">
            <h1 className="how-it-works-title">How it works</h1>
            <p className="how-it-works-subtitle">Book advertising space in three steps.</p>
            <ol className="how-it-works-steps-list">
                <li>Browse billboards on the map with location and price filters</li>
                <li>Pick your dates and pay the advance to lock the slot</li>
                <li>Submit your creative - the admin reviews and approves your campaign</li>
            </ol>
        </div>
    );
}
