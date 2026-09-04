import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import {
  ArrowRight,
  Camera,
  Check,
  Compass,
  CreditCard,
  Inbox,
  Lightbulb,
  MapPin,
  Percent,
  ShieldCheck,
  Upload,
  Wallet,
} from 'lucide-react';
import api from '../../shared/api/axios';
import { formatBDT } from '../../shared/utils/formatPrice';
import usePageTitle from '../../shared/hooks/usePageTitle';
import './HowItWorks.css';

// The owner-only "How it works" page - the plain-language guide a board owner
// gets AFTER logging in as an owner, in place of the generic 3-step version
// every logged-out visitor sees (shared/pages/HowItWorks.jsx) and the 7-step
// booking lifecycle a client sees (client/pages/HowItWorks.jsx). App.jsx picks
// between the three on `user?.role`, so this page can never be reached by a
// client, an admin, or a logged-out visitor.
//
// Everything here describes what the code actually does: the paid listing flow
// (MyBillboards -> ListingSubmissionService -> admin review), the owner's stage
// of the booking pipeline (BookingRequests -> OwnerAcceptanceService), and the
// settlement shown on the Payouts page. Deliberately written for someone who
// owns a billboard, not someone who builds software - no jargon, no field
// names, no mention of a "listing_status".

// Kept in step with the upload limits enforced in MyBillboards.jsx and
// StoreBillboardListingRequest (photo max:5120, permit_document max:10240).
const MAX_PHOTO_MB = 5;
const MAX_PERMIT_MB = 10;

// What to have on hand before opening the "List new billboard" form - one entry
// per thing that form actually asks for.
const CHECKLIST = [
  'A name for the board and the street address it stands on',
  'The exact spot on the map (the two numbers explained below)',
  'Its size, such as 20ft x 10ft, and what kind of board it is',
  'What you charge - per day, or per month',
  `One clear photo of the board (under ${MAX_PHOTO_MB} MB)`,
  `Your permit paper as a PDF or a photo (under ${MAX_PERMIT_MB} MB), and the date it runs out`,
];

// The owner's real journey, in order. `fee` is the live listing fee, so the
// number quoted here never drifts from what checkout actually charges.
function stepsFor(fee) {
  return [
    {
      icon: <MapPin size={20} />,
      title: 'Tell us about your board',
      desc: 'Open My Billboards and press "List new billboard". Fill in its name, the address, the spot on the map, its size, and the price you want per day or per month.',
    },
    {
      icon: <Camera size={20} />,
      title: 'Add a photo and your permit',
      desc: `Attach one clear photo of the board and the permit paper that lets it stand there, plus the date that permit runs out. Keep the photo under ${MAX_PHOTO_MB} MB and the permit file under ${MAX_PERMIT_MB} MB.`,
    },
    {
      icon: <CreditCard size={20} />,
      title: 'Pay the one-time listing fee',
      desc: `Saving takes you straight to a secure card and mobile-banking page to pay ${formatBDT(fee)}. You pay this once for the board, not once per booking - and you get all of it back if the board is not approved.`,
    },
    {
      icon: <ShieldCheck size={20} />,
      title: 'We check your paperwork',
      desc: 'Our team looks over your photo and permit. Once it passes, your board goes onto the public map where advertisers can find it. If something is missing you are told why, and your fee is returned.',
    },
    {
      icon: <Inbox size={20} />,
      title: 'A booking request arrives',
      desc: 'An advertiser picks your board and their dates, and pays part of the money up front. We check their campaign first, then it lands under Booking Requests as a new request for you.',
    },
    {
      icon: <Check size={20} />,
      title: 'You say yes or no',
      desc: 'Accept, and the dates are held for them - the advertiser is then given a deadline to pay the rest. Decline, and it ends there; the money they paid up front goes straight back to them, so saying no costs nobody anything.',
    },
    {
      icon: <Upload size={20} />,
      title: 'Put the ad up, then send a photo',
      desc: 'Once the advertiser has paid in full, the request moves to "ready to install". Post their artwork on the board, take a photo of it up there, and upload it. We check the photo and the campaign goes live.',
    },
    {
      icon: <Wallet size={20} />,
      title: 'You get paid',
      desc: 'Your earnings build up as an outstanding balance on the Payouts page. Save your bKash, Nagad or bank details there once, and we settle what you are owed - usually on the 10th of each month.',
    },
  ];
}

// The three money questions every owner asks, answered in one line each.
function moneyFactsFor(fee) {
  return [
    {
      icon: <CreditCard size={18} />,
      title: 'Listing fee',
      body: `${formatBDT(fee)}, once, when you add a board. Refunded in full if the board is not approved.`,
    },
    {
      icon: <Percent size={18} />,
      title: 'Our share',
      body: 'A part of each booking stays with us for running the platform. The exact taka amount is printed on every payout receipt, so nothing is hidden from you.',
    },
    {
      icon: <Wallet size={18} />,
      title: 'When you are paid',
      body: 'What you are owed is settled by us, normally on the 10th of each month, into the account you saved under Payouts.',
    },
  ];
}

export default function OwnerHowItWorks() {
  usePageTitle('How it works');

  // Same public settings endpoint the listing form reads, so the fee quoted in
  // this guide is always the one the owner is about to be charged.
  const [listingFee, setListingFee] = useState(5000);

  useEffect(() => {
    api.get('/settings/public')
      .then((res) => setListingFee(res.data.data.listing_fee ?? 5000))
      .catch(() => {});
  }, []);

  const steps = stepsFor(listingFee);
  const moneyFacts = moneyFactsFor(listingFee);

  return (
    <div className="owner-hiw-page">
      <h1 className="owner-hiw-title">How it works</h1>
      <p className="owner-hiw-subtitle">
        Your guide to putting a billboard on BillboardBD, taking bookings, and getting paid - in plain words.
      </p>

      {/* What to gather before opening the listing form */}
      <section className="owner-hiw-checklist-card">
        <h2 className="owner-hiw-checklist-title">Before you start, have these ready</h2>
        <ul className="owner-hiw-checklist-list">
          {CHECKLIST.map((item) => (
            <li className="owner-hiw-checklist-item" key={item}>
              <span className="owner-hiw-checklist-tick"><Check size={14} /></span>
              <span>{item}</span>
            </li>
          ))}
        </ul>
      </section>

      {/* The eight stages, from listing a board to being paid for it */}
      <h2 className="owner-hiw-section-heading">From your board to your bank account</h2>
      <ol className="owner-hiw-timeline">
        {steps.map((s, i) => (
          <li className="owner-hiw-step" key={s.title}>
            <div className="owner-hiw-step-icon">{s.icon}</div>
            <div className="owner-hiw-step-body">
              <div className="owner-hiw-step-num">Step {i + 1}</div>
              <h3 className="owner-hiw-step-title">{s.title}</h3>
              <p className="owner-hiw-step-desc">{s.desc}</p>
            </div>
          </li>
        ))}
      </ol>

      {/* The one part of the listing form that stops most owners */}
      <section className="owner-hiw-latlng-card">
        <div className="owner-hiw-latlng-header">
          <span className="owner-hiw-latlng-icon"><Compass size={18} /></span>
          <h2 className="owner-hiw-latlng-title">What are &quot;latitude&quot; and &quot;longitude&quot;?</h2>
        </div>

        <p className="owner-hiw-latlng-intro">
          They are simply two numbers that mark the exact place your board stands - think of them as a very precise
          address. The street address tells someone roughly where to drive; these two numbers drop the pin on our map
          within a few metres, so an advertiser browsing the map sees your board in exactly the right place instead of
          somewhere down the road.
        </p>

        <h3 className="owner-hiw-latlng-steps-title">How to find them on your phone</h3>
        <ol className="owner-hiw-latlng-steps">
          <li className="owner-hiw-latlng-step">Open Google Maps and move the map until you can see your board&apos;s spot.</li>
          <li className="owner-hiw-latlng-step">
            Press and hold on that exact spot (right-click on a computer). Two numbers appear, looking like
            <span className="owner-hiw-latlng-sample">23.780800, 90.414200</span>
          </li>
          <li className="owner-hiw-latlng-step">
            The <strong>first</strong> number is the latitude and the <strong>second</strong> is the longitude. Copy each
            one into its own box on the listing form.
          </li>
        </ol>

        <p className="owner-hiw-latlng-tip">
          <span className="owner-hiw-latlng-tip-icon"><Lightbulb size={14} /></span>
          <span>
            Quick check before you save: anywhere in Bangladesh the latitude is a number between about 21 and 26, and the
            longitude between about 88 and 93. If yours look nothing like that, the two have probably been swapped.
          </span>
        </p>
      </section>

      {/* Money, in three lines */}
      <h2 className="owner-hiw-section-heading">The money side</h2>
      <div className="owner-hiw-money-grid">
        {moneyFacts.map((f) => (
          <div className="owner-hiw-money-card" key={f.title}>
            <span className="owner-hiw-money-icon">{f.icon}</span>
            <h3 className="owner-hiw-money-card-title">{f.title}</h3>
            <p className="owner-hiw-money-card-body">{f.body}</p>
          </div>
        ))}
      </div>

      <div className="owner-hiw-cta-row">
        <Link to="/owner/billboards" className="list-my-billboard-btn">
          List my billboard <ArrowRight size={16} />
        </Link>
        <Link to="/owner/payouts" className="set-up-my-payout-details-btn">
          Set up my payout details
        </Link>
      </div>
    </div>
  );
}
