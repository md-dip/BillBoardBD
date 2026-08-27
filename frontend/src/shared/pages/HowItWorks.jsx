export default function HowItWorks() {
    return (
        <div className="page">
            <h1>How it works</h1>
            <p className="subtitle">Book advertising space in three steps.</p>
            <ol style={{marginTop: 24, lineHeight: 2, paddingLeft: 20}}>
                <li>Browse billboards on the map with location and price filters</li>
                <li>Pick your dates and pay the advance to lock the slot</li>
                <li>Submit your creative — the admin reviews and approves your campaign</li>
            </ol>
        </div>
    );
}