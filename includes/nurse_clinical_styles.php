<?php
function nurse_clinical_styles(): string {
    return '
.clinical-shell{max-width:1180px;margin:28px auto;padding:0 20px 42px}
.clinical-hero{background:#073b4c;color:#fff;border-radius:8px;padding:26px 24px;margin-bottom:18px;box-shadow:0 14px 34px rgba(7,59,76,.18)}
.clinical-hero h1{margin:0 0 6px}.clinical-hero p{margin:0;color:#e6f7ff}
.clinical-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}@media(max-width:950px){.clinical-grid{grid-template-columns:1fr}}
.clinical-card{background:#fff;border-radius:14px;padding:20px;box-shadow:0 4px 18px rgba(0,0,0,.06);margin-bottom:16px}
.clinical-card h2{margin:0 0 12px;color:#0077b6;font-size:1.15rem}
.clinical-tabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px}
.clinical-tabs a,.clinical-tabs button{border:1px solid #d5e8f6;background:#fff;color:#0b4f80;border-radius:999px;padding:8px 13px;font-weight:700;text-decoration:none;cursor:pointer}
.clinical-tabs .active{background:#0f7cc2;color:#fff}
.clinical-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}.clinical-form-grid .full{grid-column:1/-1}@media(max-width:700px){.clinical-form-grid{grid-template-columns:1fr}}
.field label{display:block;font-weight:700;color:#344d5f;margin-bottom:5px}.field input,.field textarea,.field select{width:100%;box-sizing:border-box;border:1px solid #cbdce8;border-radius:8px;padding:10px;font:inherit}.field textarea{min-height:90px}
.clinical-btn{background:#0077b6;color:#fff;border:none;border-radius:8px;padding:11px 15px;font-weight:800;cursor:pointer;text-decoration:none;display:inline-flex}
.clinical-btn.secondary{background:#eef7ff;color:#0b4f80;border:1px solid #cfe4f4}
.medical-section-table{width:100%;border-collapse:collapse;margin-top:8px}.medical-section-table th,.medical-section-table td{border:1px solid #e2e9f0;padding:8px;vertical-align:top;text-align:left}.medical-section-table th{width:34%;background:#f4f8fb;color:#60727d}
.recent-list{display:none;gap:10px}.recent-list.active{display:grid}.recent-item{border:1px solid #e2e9f0;background:#f8fbff;border-radius:10px;padding:12px}.recent-item strong{display:block;color:#073b4c}.recent-item small{color:#60727d}
.record-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px;padding-top:10px;border-top:1px solid #dce8ef}
.record-action-btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;min-height:34px;padding:7px 11px;border-radius:7px;font-size:.88rem;font-weight:800;text-decoration:none;border:1px solid transparent;line-height:1.1}
.record-action-btn span{display:inline-flex;align-items:center;justify-content:center;width:19px;height:19px;border-radius:50%;font-size:.66rem;font-weight:900}
.record-action-btn.print{background:#0f7cc2;color:#fff;box-shadow:0 8px 18px rgba(15,124,194,.16)}
.record-action-btn.print span{background:rgba(255,255,255,.2);color:#fff}
.record-action-btn.download{background:#eef7ff;color:#0b4f80;border-color:#cfe4f4}
.record-action-btn.download span{background:#d9edf8;color:#0b4f80}
.record-action-btn:hover{transform:translateY(-1px)}
@media(max-width:560px){.record-action-btn{width:100%}}
';
}
