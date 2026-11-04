import { AdminOrders } from './AdminOrders';
import { AdminProducts } from './AdminProducts';
import { CreateProductForm } from './CreateProductForm';

export function AdminPage() {
  return (
    <div>
      <h1>لوحة المسؤول</h1>
      <CreateProductForm />
      <AdminProducts />
      <AdminOrders />
    </div>
  );
}
