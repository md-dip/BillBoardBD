import { Link } from 'react-router-dom';
import { Search, Clock, Megaphone, ShieldCheck, UserCheck, CreditCard, Camera } from 'lucide-react';
import usePageTitle from '../../shared/hooks/usePageTitle';
import './HowItWorks.css';

// The client-only "How it works" page - shows the REAL 7-step booking
// lifecycle a client's own request goes through. Anyone not logged in as
// a client (including logged-out visitors) still sees the simple 3-step
// version at shared/pages/HowItWorks.jsx.
const STEPS = [
    {
        icon: <Search size={20} />,
        title: 'Search & compare',
        desc: 'Browse billboards on the map, filter by radius, type, and price to find the right spot.',
    },
    {
        icon: <Clock size={20} />,
        title: 'Hold your dates',
        desc: "Pick a start and end date they're locked to you for 15 minutes while you finish the request.",
    },
    {
        icon: <Megaphone size={20} />,
        title: 'Submit your campaign',
        desc: 'Add your brand, ad category, and creative, then pay a 30% advance to submit the request.',
    },
    {
        icon: <ShieldCheck size={20} />,
        title: 'Admin review',
        desc: "Our team checks the request and the billboard's permit status usually within 6 hours.",
    },
    {
        icon: <UserCheck size={20} />,
        title: 'Owner accepts',
        desc: 'The billboard owner accepts your request, and a final payment due date is set.',
    },
    {
        icon: <CreditCard size={20} />,
        title: 'Pay the balance',
        desc: 'Pay the remaining amount before the due date to confirm the installation.',
    },
    {
        icon: <Camera size={20} />,
        title: 'Installation verified',
        desc: 'The owner posts your ad and uploads proof once admin verifies it, your campaign goes live.',
    },
];

export default function HowItWorks() {
    usePageTitle('How it works');

    return (
        <div className="hiw-page">
            <h1>How it works</h1>
            <p className="hiw-subtitle">
                Here&apos;s exactly what happens after you request a billboard  you&apos;ll get a notification at every step.
            </p>

            <ol className="hiw-timeline">
                {STEPS.map((s, i) => (
                    <li className="hiw-step" key={s.title}>
                        <div className="hiw-step-icon">{s.icon}</div>
                        <div className="hiw-step-body">
                            <div className="hiw-step-num">Step {i + 1}</div>
                            <h3>{s.title}</h3>
                            <p>{s.desc}</p>
                        </div>
                    </li>
                ))}
            </ol>

            <Link to="/billboards" className="browse-billboards-btn">Browse billboards</Link>
        </div>
    );
}
