import { PageHeader } from '../../components/PageHeader';
import { AdminOrders } from './AdminOrders';
import { AdminProducts } from './AdminProducts';
import { CreateProductForm } from './CreateProductForm';

export function AdminPage() {
  return (
    <div className="admin-page">
      <PageHeader
        title="لوحة المسؤول"
        subtitle="إضافة المنتجات، إدارة الأسعار والمخزون، ومتابعة الطلبات."
      />
      <CreateProductForm />
      <AdminProducts />
      <AdminOrders />
    </div>
  );
}
