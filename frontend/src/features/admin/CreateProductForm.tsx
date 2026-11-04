import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from '@tanstack/react-router';
import { type SyntheticEvent, useState } from 'react';
import { ErrorNotice } from '../../components/ErrorNotice';
import { Field, type FieldErrors, serverFieldErrors } from '../../components/forms';
import { StockBadge } from '../../components/StockBadge';
import { showToast } from '../../components/toast';
import { formatMoney, imagePath } from '../../lib/format';
import { categoriesQuery } from '../catalog/queries';
import { useCreateProduct } from './mutations';

/** Reads the picked file as a data: URL (CSP img-src allows 'self' and data: only). */
function readPreview(file: File, done: (url: string | null) => void): void {
  const reader = new FileReader();
  reader.onload = () => {
    done(typeof reader.result === 'string' ? reader.result : null);
  };
  reader.readAsDataURL(file);
}

export function CreateProductForm() {
  const queryClient = useQueryClient();
  const categories = useQuery(categoriesQuery);
  const create = useCreateProduct();
  const [name, setName] = useState('');
  const [description, setDescription] = useState('');
  const [price, setPrice] = useState('');
  const [categoryId, setCategoryId] = useState('');
  const [initialStock, setInitialStock] = useState('0');
  const [image, setImage] = useState<File | null>(null);
  const [preview, setPreview] = useState<string | null>(null);
  const [clientErrors, setClientErrors] = useState<FieldErrors>({});

  const errors = { ...serverFieldErrors(create.error), ...clientErrors };

  function onSubmit(event: SyntheticEvent) {
    event.preventDefault();
    const found: Record<string, string[]> = {};
    const priceMajor = Number(price);
    const stock = Number(initialStock);
    if (name.trim().length < 2) found['name'] = ['الاسم يجب أن يكون حرفين على الأقل.'];
    if (!(priceMajor > 0)) found['price_minor'] = ['أدخل سعراً أكبر من صفر (بالليرة السورية).'];
    if (categoryId === '') found['category_id'] = ['اختر التصنيف.'];
    if (!Number.isInteger(stock) || stock < 0)
      found['initial_stock'] = ['أدخل عدداً صحيحاً صفراً أو أكثر.'];
    if (image === null)
      found['image'] = ['اختر صورة المنتج (JPEG أو PNG أو WebP، حتى 2 ميغابايت).'];
    setClientErrors(found);
    if (Object.keys(found).length > 0 || image === null) return;

    create.mutate(
      {
        name: name.trim(),
        description: description.trim(),
        priceMinor: Math.round(priceMajor * 100),
        categoryId,
        initialStock: stock,
        image,
      },
      {
        onSuccess: () => {
          showToast('تمت إضافة المنتج');
          void queryClient.invalidateQueries({ queryKey: ['products'] });
          setName('');
          setDescription('');
          setPrice('');
          setInitialStock('0');
          setImage(null);
          setPreview(null);
        },
      },
    );
  }

  const created = create.isSuccess ? create.data.data : null;

  return (
    <section>
      <h2>١. إضافة منتج</h2>
      {create.error !== null && <ErrorNotice error={create.error} />}
      {created !== null && (
        <div className="card created">
          <p className="success">تمت إضافة المنتج</p>
          <img src={imagePath(created.image_url)} alt={created.name} />
          <h3>{created.name}</h3>
          <p className="price">{formatMoney(created.price)}</p>
          <p>
            <StockBadge stock={created.stock} />
          </p>
          <Link to="/products/$productId" params={{ productId: created.id }}>
            فتح صفحة المنتج
          </Link>
        </div>
      )}
      <form onSubmit={onSubmit} noValidate>
        <Field label="الاسم" errors={errors['name']}>
          {(props) => (
            <input
              {...props}
              type="text"
              value={name}
              onChange={(e) => {
                setName(e.target.value);
              }}
            />
          )}
        </Field>
        <Field label="الوصف" errors={errors['description']}>
          {(props) => (
            <textarea
              {...props}
              value={description}
              onChange={(e) => {
                setDescription(e.target.value);
              }}
            />
          )}
        </Field>
        <Field label="السعر (ل.س)" errors={errors['price_minor']}>
          {(props) => (
            <input
              {...props}
              type="number"
              step="0.01"
              min="0"
              value={price}
              onChange={(e) => {
                setPrice(e.target.value);
              }}
            />
          )}
        </Field>
        <Field label="التصنيف" errors={errors['category_id']}>
          {(props) => (
            <select
              {...props}
              value={categoryId}
              onChange={(e) => {
                setCategoryId(e.target.value);
              }}
            >
              <option value="">اختر…</option>
              {categories.data?.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name}
                </option>
              ))}
            </select>
          )}
        </Field>
        <Field label="الكمية الابتدائية" errors={errors['initial_stock']}>
          {(props) => (
            <input
              {...props}
              type="number"
              min="0"
              value={initialStock}
              onChange={(e) => {
                setInitialStock(e.target.value);
              }}
            />
          )}
        </Field>
        <Field label="الصورة" errors={errors['image']}>
          {(props) => (
            <input
              {...props}
              type="file"
              accept="image/jpeg,image/png,image/webp"
              onChange={(e) => {
                const file = e.target.files?.[0] ?? null;
                setImage(file);
                setPreview(null);
                if (file !== null) readPreview(file, setPreview);
              }}
            />
          )}
        </Field>
        {preview !== null && <img className="detail-img" src={preview} alt="معاينة الصورة" />}
        <button type="submit" disabled={create.isPending}>
          {create.isPending ? 'جارٍ الحفظ…' : 'حفظ المنتج'}
        </button>
      </form>
    </section>
  );
}
