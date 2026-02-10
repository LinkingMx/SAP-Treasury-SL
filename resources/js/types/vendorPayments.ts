import type { BankAccount, Branch, User } from '.';

export interface VendorPaymentBatch {
    id: number;
    uuid: string;
    branch_id: number;
    bank_account_id: number;
    user_id: number;
    filename: string;
    total_invoices: number;
    total_payments: number;
    total_amount: string;
    status: 'pending' | 'processing' | 'completed' | 'failed';
    error_message: string | null;
    processed_at: string | null;
    created_at: string;
    updated_at: string;
    branch?: Branch;
    bank_account?: BankAccount;
    user?: User;
}

export interface VendorPaymentInvoice {
    id: number;
    batch_id: number;
    card_code: string;
    card_name: string | null;
    doc_date: string;
    transfer_date: string;
    transfer_account: string;
    line_num: number;
    doc_entry: number;
    invoice_type: string;
    sum_applied: string;
    sap_doc_num: number | null;
    error: string | null;
    created_at: string;
    updated_at: string;
}

export interface VendorPaymentBatchDetail extends VendorPaymentBatch {
    invoices: VendorPaymentInvoice[];
}

export interface VendorPaymentGroup {
    card_code: string;
    card_name: string | null;
    total_amount: number;
    invoice_count: number;
    invoices: VendorPaymentInvoice[];
    sap_doc_num: number | null;
    has_error: boolean;
    error: string | null;
}

export interface ImportError {
    row: number;
    error: string;
}

export interface BatchResult {
    uuid: string;
    total_invoices: number;
    total_payments: number;
    total_amount: string;
    processed_at: string;
}

export interface PaginatedResponse<T> {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    per_page: number;
}
