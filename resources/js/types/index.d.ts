import { InertiaLinkProps } from '@inertiajs/react';
import { LucideIcon } from 'lucide-react';

export interface Auth {
    user: User;
}

export interface Branch {
    id: number;
    name: string;
}

export interface BankAccount {
    id: number;
    branch_id: number;
    name: string;
    account: string;
}

export interface ImportError {
    row: number;
    error: string;
}

export type BatchStatus = 'pending' | 'processing' | 'completed' | 'failed';

export interface BatchResult {
    uuid: string;
    total_records: number;
    total_debit: string;
    total_credit: string;
    status: BatchStatus;
    status_label: string;
    processed_at: string;
}

export interface Batch {
    id: number;
    uuid: string;
    branch_id: number;
    bank_account_id: number;
    user_id: number;
    filename: string;
    total_records: number;
    total_debit: string;
    total_credit: string;
    status: BatchStatus;
    processed_at: string;
    created_at: string;
    updated_at: string;
}

export interface Transaction {
    id: number;
    batch_id: number;
    sequence: number;
    due_date: string;
    memo: string;
    debit_amount: string;
    credit_amount: string;
    counterpart_account: string;
    sap_number: number | null;
    error: string | null;
}

export interface BatchDetail {
    id: number;
    uuid: string;
    filename: string;
    total_records: number;
    total_debit: string;
    total_credit: string;
    status: BatchStatus;
    status_label: string;
    processed_at: string;
    branch: Branch;
    bank_account: BankAccount;
    user: string | null;
    transactions: Transaction[];
}

export interface PaginatedResponse<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavGroup {
    title: string;
    items: NavItem[];
}

export interface NavItem {
    title: string;
    href: NonNullable<InertiaLinkProps['href']> | string;
    icon?: LucideIcon | null;
    isActive?: boolean;
    external?: boolean;
}

export interface SharedData {
    name: string;
    auth: Auth;
    sidebarOpen: boolean;
    [key: string]: unknown;
}

export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    two_factor_enabled?: boolean;
    created_at: string;
    updated_at: string;
    [key: string]: unknown; // This allows for additional properties...
}
