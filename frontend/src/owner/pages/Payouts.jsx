import { useEffect, useState } from 'react';
import { Wallet, Pencil } from 'lucide-react';
import api from '../../shared/api/axios';
import OwnerShell from '../components/OwnerShell';
import { formatBDT } from '../../shared/utils/formatPrice';
import './Payouts.css';

const BLANK_DETAILS = {
  payout_method: 'bkash',
  payout_account_name: '',
  payout_account_number: '',
  payout_bank_name: '',
  payout_branch: '',
};

const METHOD_LABEL = { bkash: 'bKash', nagad: 'Nagad', bank: 'Bank transfer' };

export default function OwnerPayouts() {
  const [outstanding, setOutstanding] = useState(0);
  const [history, setHistory] = useState([]);
  // savedDetails: what's actually on the server right now (null = never set up).
  // formDetails: what's currently typed into the edit form.
  const [savedDetails, setSavedDetails] = useState(null);
  const [formDetails, setFormDetails] = useState(BLANK_DETAILS);
  const [isEditing, setIsEditing] = useState(false);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [saveError, setSaveError] = useState('');

  function load() {
    return api.get('/owner/payouts').then((res) => {
      setOutstanding(res.data.data.outstanding);
      setHistory(res.data.data.history);
      const existing = res.data.data.payout_details;
      if (existing?.payout_method) {
        setSavedDetails(existing);
        setIsEditing(false);
      } else {
        setSavedDetails(null);
        setIsEditing(true); // nothing saved yet — go straight to the form
      }
    });
  }

  useEffect(() => {
    load().finally(() => setLoading(false));
  }, []);

  function startEditing() {
    setFormDetails(savedDetails ? { ...BLANK_DETAILS, ...savedDetails } : BLANK_DETAILS);
    setSaveError('');
    setIsEditing(true);
  }

  function cancelEditing() {
    setSaveError('');
    setIsEditing(false);
  }

  async function handleSaveDetails(e) {
    e.preventDefault();
    setSaving(true);
    setSaveError('');
    try {
      const res = await api.put('/owner/payout-details', formDetails);
      setSavedDetails(res.data.data);
      setIsEditing(false);
    } catch (err) {
      setSaveError(err.response?.data?.message || 'Could not save your payout details.');
    } finally {
      setSaving(false);
    }
  }

  if (loading) {
    return (
      <OwnerShell title="Payouts">
        <p className="payouts-muted">Loading...</p>
      </OwnerShell>
    );
  }

  const isBank = formDetails.payout_method === 'bank';

  return (
    <OwnerShell title="Payouts">
      <div className="payouts-kpi-grid">
        <div className="payouts-kpi-card">
          <div className="payouts-kpi-header">
            <span className="payouts-kpi-label">Outstanding balance</span>
            <span className="payouts-kpi-icon success"><Wallet size={16} /></span>
          </div>
          <div className="payouts-kpi-value">{formatBDT(outstanding)}</div>
        </div>
      </div>

      <p className="payouts-muted payouts-mb-4">Payouts are typically settled by admin on the 10th of each month.</p>

      <h3 className="payouts-section-title">Payout history</h3>
      <div className="payouts-card payouts-mb-4">
        <table className="payouts-table">
          <thead>
            <tr>
              <th>Amount</th>
              <th>Method</th>
              <th>Reference</th>
              <th>Paid at</th>
            </tr>
          </thead>
          <tbody>
            {history.map((p) => (
              <tr key={p.id}>
                <td className="payouts-amount-cell">{formatBDT(p.amount)}</td>
                <td className="payouts-method-cell">{p.method || 'N/A'}</td>
                <td>{p.reference || 'N/A'}</td>
                <td>{p.paid_at ? p.paid_at.slice(0, 10) : 'N/A'}</td>
              </tr>
            ))}
            {history.length === 0 && (
              <tr>
                <td colSpan={4} className="payouts-table-empty">
                  You haven&apos;t received any payouts yet.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>

      <h3 className="payouts-section-title">Payout details</h3>
      <p className="payouts-muted payouts-mb-4">
        Tell admin where to send your payouts. This is read when a payout is manually recorded for you.
      </p>

      {!isEditing ? (
        // ---- Saved state: show what's on file, read-only, with an Edit button ----
        <div className="payouts-card">
          <div className="payouts-card-body">
            <div className="payouts-details-header">
              <span className="payouts-details-saved-badge">Saved</span>
              <button type="button" className="payouts-edit-details-btn" onClick={startEditing}>
                <Pencil size={14} /> Edit
              </button>
            </div>
            <div className="payouts-details-grid">
              <div>
                <span className="payouts-details-label">Payout method</span>
                <div className="payouts-details-value">{METHOD_LABEL[savedDetails.payout_method] || savedDetails.payout_method}</div>
              </div>
              <div>
                <span className="payouts-details-label">{isBank ? 'Account holder name' : 'Account name'}</span>
                <div className="payouts-details-value">{savedDetails.payout_account_name}</div>
              </div>
              <div>
                <span className="payouts-details-label">
                  {savedDetails.payout_method === 'bank' ? 'Account number' : `${METHOD_LABEL[savedDetails.payout_method]} number`}
                </span>
                <div className="payouts-details-value">{savedDetails.payout_account_number}</div>
              </div>
              {savedDetails.payout_method === 'bank' && (
                <>
                  <div>
                    <span className="payouts-details-label">Bank name</span>
                    <div className="payouts-details-value">{savedDetails.payout_bank_name}</div>
                  </div>
                  <div>
                    <span className="payouts-details-label">Branch</span>
                    <div className="payouts-details-value">{savedDetails.payout_branch || 'N/A'}</div>
                  </div>
                </>
              )}
            </div>
          </div>
        </div>
      ) : (
        // ---- Edit state: the form ----
        <div className="payouts-card">
          <div className="payouts-card-body">
            <form onSubmit={handleSaveDetails}>
              {saveError && <p className="payouts-error-text">{saveError}</p>}

              <div className="payouts-form-row">
                <label className="payouts-form-label">Payout method</label>
                <select
                  className="payouts-form-select"
                  value={formDetails.payout_method}
                  onChange={(e) => setFormDetails({ ...formDetails, payout_method: e.target.value })}
                >
                  <option value="bkash">bKash</option>
                  <option value="nagad">Nagad</option>
                  <option value="bank">Bank transfer</option>
                </select>
              </div>

              <div className="payouts-form-grid">
                <div>
                  <label className="payouts-form-label">
                    {isBank ? 'Account holder name' : 'Account name'}
                  </label>
                  <input
                    className="payouts-form-input"
                    value={formDetails.payout_account_name}
                    onChange={(e) => setFormDetails({ ...formDetails, payout_account_name: e.target.value })}
                    required
                  />
                </div>
                <div>
                  <label className="payouts-form-label">
                    {isBank ? 'Account number' : `${formDetails.payout_method === 'nagad' ? 'Nagad' : 'bKash'} number`}
                  </label>
                  <input
                    className="payouts-form-input"
                    value={formDetails.payout_account_number}
                    onChange={(e) => setFormDetails({ ...formDetails, payout_account_number: e.target.value })}
                    required
                  />
                </div>
              </div>

              {isBank && (
                <div className="payouts-form-grid">
                  <div>
                    <label className="payouts-form-label">Bank name</label>
                    <input
                      className="payouts-form-input"
                      value={formDetails.payout_bank_name}
                      onChange={(e) => setFormDetails({ ...formDetails, payout_bank_name: e.target.value })}
                      required
                    />
                  </div>
                  <div>
                    <label className="payouts-form-label">Branch</label>
                    <input
                      className="payouts-form-input"
                      value={formDetails.payout_branch}
                      onChange={(e) => setFormDetails({ ...formDetails, payout_branch: e.target.value })}
                    />
                  </div>
                </div>
              )}

              <div className="payouts-form-actions">
                <button type="submit" className="payouts-save-details-btn" disabled={saving}>
                  {saving ? 'Saving...' : 'Save payout details'}
                </button>
                {savedDetails && (
                  <button type="button" className="payouts-cancel-edit-btn" onClick={cancelEditing} disabled={saving}>
                    Cancel
                  </button>
                )}
              </div>
            </form>
          </div>
        </div>
      )}
    </OwnerShell>
  );
}
